import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api.dart';
import 'wake_word.dart';

/// Голос ZAK — тот же серверный голос, что на сайте (POST /assistant/tts, сейчас ru-RU-SvetlanaNeural).
///
/// Отвечает «Да, слушаю вас» после «Зак» и озвучивает ответы ZAK. Пока ZAK говорит,
/// слушатель «Зак» на паузе, чтобы приложение не будило само себя.
class ZakVoice extends ChangeNotifier {
  ZakVoice({required this.api, required this.wakeWord, FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  final Api api;
  final WakeWord wakeWord;
  final FlutterSecureStorage _storage;

  static const ackText = 'Да, слушаю вас';
  static const _autoKey = 'zak_auto_speak';
  static const _micHold = 'zak_voice';

  /// Озвучивать каждый ответ ZAK (переключатель в шапке чата). Ответ на голосовой вопрос озвучивается всегда.
  bool autoSpeak = false;

  bool _speaking = false;
  bool get speaking => _speaking;

  AudioPlayer? _player;
  Completer<void>? _done;
  int _playId = 0;
  Uint8List? _ack;
  Future<Uint8List?>? _ackLoading;

  Future<void> load() async {
    autoSpeak = await _storage.read(key: _autoKey) == '1';
    notifyListeners();
  }

  Future<void> setAutoSpeak(bool on) async {
    autoSpeak = on;
    if (!on) await stop();
    notifyListeners();
    await _storage.write(key: _autoKey, value: on ? '1' : '0');
  }

  /// Заранее скачать «Да, слушаю вас», чтобы после «Зак» ответ звучал без задержки.
  Future<Uint8List?> prefetchAck() {
    if (_ack != null) return Future.value(_ack);
    return _ackLoading ??= api.assistantTts(ackText).then((b) {
      _ack = b;
      _ackLoading = null;
      return b;
    });
  }

  /// «Да, слушаю вас». Завершается, когда фраза договорена (или сразу, если голос недоступен).
  Future<void> sayAck() async {
    final bytes = await prefetchAck().timeout(const Duration(seconds: 4), onTimeout: () => null);
    if (bytes != null) await _play(bytes);
  }

  /// Озвучить текст ответа. Завершается по окончании речи, по [stop] или при ошибке.
  Future<void> speak(String text) async {
    final clean = text.trim();
    if (clean.isEmpty) return;
    final id = ++_playId;
    final bytes = await api.assistantTts(clean);
    if (bytes == null || id != _playId) return;
    await _play(bytes, id: id);
  }

  Future<void> _play(Uint8List bytes, {int? id}) async {
    final playId = id ?? ++_playId;
    await _stopPlayer();
    if (playId != _playId) return;
    final player = _player ??= AudioPlayer();
    final done = _done = Completer<void>();
    StreamSubscription<void>? sub;
    sub = player.onPlayerComplete.listen((_) {
      if (!done.isCompleted) done.complete();
    });
    _speaking = true;
    notifyListeners();
    await wakeWord.hold(_micHold);
    try {
      await player.play(BytesSource(bytes, mimeType: 'audio/mpeg'));
      await done.future.timeout(const Duration(minutes: 3), onTimeout: () {});
    } catch (_) {
      // плеер не смог воспроизвести — молча продолжаем
    } finally {
      await sub.cancel();
      // Если уже запущена следующая фраза, состояние и микрофон теперь её
      if (identical(_done, done)) {
        _speaking = false;
        notifyListeners();
        await wakeWord.release(_micHold);
      }
    }
  }

  Future<void> _stopPlayer() async {
    final done = _done;
    if (done != null && !done.isCompleted) done.complete();
    try {
      await _player?.stop();
    } catch (_) {}
  }

  /// Замолчать (новый вопрос, уход с экрана, выключение озвучки).
  Future<void> stop() async {
    _playId++;
    await _stopPlayer();
  }

  @override
  void dispose() {
    _player?.dispose();
    super.dispose();
  }
}
