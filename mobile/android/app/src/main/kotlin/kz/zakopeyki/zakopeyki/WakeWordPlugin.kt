package kz.zakopeyki.zakopeyki

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Handler
import android.os.Looper
import androidx.core.content.ContextCompat
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.EventChannel
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import org.json.JSONObject
import org.vosk.Model
import org.vosk.Recognizer
import org.vosk.android.RecognitionListener
import org.vosk.android.SpeechService
import java.io.BufferedInputStream
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import java.util.zip.ZipInputStream

/**
 * Голосовое пробуждение «Зак» на офлайн-распознавании Vosk.
 *
 * Модель (vosk-model-small-ru, ~45 МБ) скачивается один раз при включении функции
 * и хранится в filesDir. Речь распознаётся целиком на устройстве, мы смотрим только,
 * есть ли в ней слово-пробуждение; текст и звук никуда не уходят и не сохраняются.
 *
 * Почему без грамматики: с грамматикой ["зак", "[unk]"] Vosk притягивает к «зак» почти
 * любую речь («сколько стоит доставка» → «зэк»). Полный словарь даёт конкуренцию похожим
 * словам («закажи», «закат», «казак») — на тестовых фразах ложных срабатываний не было.
 *
 * Каналы:
 *  - kz.zakopeyki/wake_word: isModelReady, downloadModel, start, stop;
 *  - kz.zakopeyki/wake_word/events: {type: wake} | {type: progress, value} | {type: error, message}.
 */
class WakeWordPlugin(private val context: Context, messenger: BinaryMessenger) :
    MethodChannel.MethodCallHandler, EventChannel.StreamHandler, RecognitionListener {

    companion object {
        private const val METHODS = "kz.zakopeyki/wake_word"
        private const val EVENTS = "kz.zakopeyki/wake_word/events"
        private const val MODEL_URL = "https://alphacephei.com/vosk/models/vosk-model-small-ru-0.22.zip"
        private const val MODEL_DIR = "vosk-model-small-ru-0.22"
        private const val SAMPLE_RATE = 16000f

        /** Как модель записывает «Зак»: отдельное слово часто выходит «заг», реже «зэк». */
        private val WAKE_WORDS = setOf("зак", "заг", "зэк")
    }

    private val methods = MethodChannel(messenger, METHODS)
    private val events = EventChannel(messenger, EVENTS)
    private val main = Handler(Looper.getMainLooper())
    private val worker = Executors.newSingleThreadExecutor()

    private var sink: EventChannel.EventSink? = null
    private var model: Model? = null
    private var recognizer: Recognizer? = null
    private var service: SpeechService? = null

    /** Одна фраза даёт много частичных результатов — срабатываем на неё один раз. */
    private var firedInUtterance = false

    @Volatile
    private var downloading = false

    init {
        methods.setMethodCallHandler(this)
        events.setStreamHandler(this)
    }

    private val root get() = File(context.filesDir, "vosk")
    private val modelPath get() = File(root, MODEL_DIR)
    private val isModelReady get() = File(modelPath, ".complete").exists()

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        when (call.method) {
            "isModelReady" -> result.success(isModelReady)
            "downloadModel" -> downloadModel(result)
            "start" -> worker.execute {
                try {
                    startListening()
                    main.post { result.success(null) }
                } catch (e: Exception) {
                    stopListening()
                    main.post { result.error("start_failed", e.message ?: "Не удалось включить микрофон", null) }
                }
            }
            "stop" -> worker.execute {
                stopListening()
                main.post { result.success(null) }
            }
            else -> result.notImplemented()
        }
    }

    // --- распознавание ---

    private fun startListening() {
        if (service != null) return
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            throw IOException("Нет доступа к микрофону")
        }
        if (!isModelReady) throw IOException("Голосовая модель не загружена")
        val m = model ?: Model(modelPath.absolutePath).also { model = it }
        val rec = Recognizer(m, SAMPLE_RATE)
        recognizer = rec
        firedInUtterance = false
        service = SpeechService(rec, SAMPLE_RATE).also { it.startListening(this) }
    }

    private fun stopListening() {
        service?.let {
            it.stop()
            it.shutdown()
        }
        service = null
        recognizer?.close()
        recognizer = null
    }

    /** Остановить при уходе приложения в фон (страховка к логике на стороне Dart). */
    fun stopForBackground() {
        worker.execute { stopListening() }
    }

    fun dispose() {
        methods.setMethodCallHandler(null)
        events.setStreamHandler(null)
        worker.execute {
            stopListening()
            model?.close()
            model = null
        }
        worker.shutdown()
    }

    private fun containsWakeWord(hypothesis: String?, key: String): Boolean {
        val text = try {
            JSONObject(hypothesis ?: return false).optString(key)
        } catch (_: Exception) {
            return false
        }
        return text.split(' ').any { it in WAKE_WORDS }
    }

    override fun onPartialResult(hypothesis: String?) {
        if (!firedInUtterance && containsWakeWord(hypothesis, "partial")) {
            firedInUtterance = true
            emit(mapOf("type" to "wake"))
        }
    }

    override fun onResult(hypothesis: String?) {
        if (!firedInUtterance && containsWakeWord(hypothesis, "text")) emit(mapOf("type" to "wake"))
        firedInUtterance = false
    }

    override fun onFinalResult(hypothesis: String?) {
        firedInUtterance = false
    }

    override fun onError(exception: Exception?) {
        worker.execute { stopListening() }
        emit(mapOf("type" to "error", "message" to (exception?.message ?: "Ошибка распознавания")))
    }

    override fun onTimeout() {}

    // --- загрузка модели ---

    private fun downloadModel(result: MethodChannel.Result) {
        if (isModelReady) {
            result.success(true)
            return
        }
        if (downloading) {
            result.error("busy", "Модель уже загружается", null)
            return
        }
        downloading = true
        Thread {
            val tmp = File(root, "model.zip.part")
            try {
                root.mkdirs()
                val conn = (URL(MODEL_URL).openConnection() as HttpURLConnection).apply {
                    connectTimeout = 15_000
                    readTimeout = 30_000
                }
                if (conn.responseCode != HttpURLConnection.HTTP_OK) throw IOException("HTTP ${conn.responseCode}")
                val total = conn.contentLengthLong
                conn.inputStream.use { input ->
                    FileOutputStream(tmp).use { out ->
                        val buf = ByteArray(64 * 1024)
                        var done = 0L
                        var lastPercent = -1
                        while (true) {
                            val n = input.read(buf)
                            if (n < 0) break
                            out.write(buf, 0, n)
                            done += n
                            if (total > 0) {
                                val percent = (done * 100 / total).toInt()
                                if (percent != lastPercent) {
                                    lastPercent = percent
                                    // распаковка занимает последние ~10% шкалы
                                    emit(mapOf("type" to "progress", "value" to percent * 0.009))
                                }
                            }
                        }
                    }
                }
                modelPath.deleteRecursively()
                unzip(tmp, root)
                if (!File(modelPath, "am/final.mdl").exists()) throw IOException("Архив модели повреждён")
                File(modelPath, ".complete").createNewFile()
                emit(mapOf("type" to "progress", "value" to 1.0))
                main.post { result.success(true) }
            } catch (e: Exception) {
                modelPath.deleteRecursively()
                main.post { result.error("download_failed", e.message ?: "Ошибка сети", null) }
            } finally {
                tmp.delete()
                downloading = false
            }
        }.start()
    }

    private fun unzip(zipFile: File, target: File) {
        val base = target.canonicalPath + File.separator
        ZipInputStream(BufferedInputStream(FileInputStream(zipFile))).use { zip ->
            var entry = zip.nextEntry
            while (entry != null) {
                val out = File(target, entry.name)
                if (!out.canonicalPath.startsWith(base)) throw IOException("Недопустимый путь в архиве")
                if (entry.isDirectory) {
                    out.mkdirs()
                } else {
                    out.parentFile?.mkdirs()
                    FileOutputStream(out).use { zip.copyTo(it) }
                }
                entry = zip.nextEntry
            }
        }
    }

    // --- события в Dart ---

    override fun onListen(arguments: Any?, events: EventChannel.EventSink?) {
        sink = events
    }

    override fun onCancel(arguments: Any?) {
        sink = null
    }

    private fun emit(event: Map<String, Any>) {
        main.post { sink?.success(event) }
    }
}
