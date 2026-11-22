package kz.zakopeyki.zakopeyki

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine

class MainActivity : FlutterActivity() {
    private var wakeWord: WakeWordPlugin? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        wakeWord = WakeWordPlugin(applicationContext, flutterEngine.dartExecutor.binaryMessenger)
    }

    override fun onStop() {
        // Слушаем только пока приложение на экране; Dart сам включит распознавание при возврате.
        wakeWord?.stopForBackground()
        super.onStop()
    }

    override fun onDestroy() {
        wakeWord?.dispose()
        wakeWord = null
        super.onDestroy()
    }
}
