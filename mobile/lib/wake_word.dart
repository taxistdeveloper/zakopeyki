import 'dart:async';

import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:permission_handler/permission_handler.dart';

/// Голосовое пробуждение по слову «Zak» («Зак»).
///
/// Распознавание офлайн (Vosk, см. android/.../WakeWordPlugin.kt): модель скачивается один раз
/// при включении, звук не покидает устройство. Слушаем только пока приложение на экране и
/// пока микрофон не занят (диктовка в ZAK, прямой эфир) — для этого есть [hold]/[release].
/// По умолчанию выключено; выбор хранится на устройстве.
class WakeWord extends ChangeNotifier {
  WakeWord({FlutterSecureStorage? storage}) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static const _methods = MethodChannel('kz.zakopeyki/wake_word');
  static const _events = EventChannel('kz.zakopeyki/wake_word/events');
  static const _enabledKey = 'wake_word_enabled';

  /// Пользователь включил функцию.
  bool enabled = false;

  /// Прогресс загрузки модели 0..1; null — загрузки нет.
  double? progress;

  /// Последняя ошибка (нет доступа к микрофону, сбой распознавания…).
  String? error;

  bool _listening = false;
  bool _foreground = true;
  final _holds = <String>{};
  final _wakes = StreamController<void>.broadcast();
  StreamSubscription<dynamic>? _eventsSub;
  AppLifecycleListener? _lifecycle;
  Future<void> _queue = Future.value();

  /// Микрофон сейчас слушает слово-пробуждение.
  bool get listening => _listening;

  bool get downloading => progress != null;

  /// Срабатывает, когда услышали «Зак».
  Stream<void> get onWake => _wakes.stream;

  String get label => !enabled ? 'Выкл' : (_listening ? 'Слушает' : 'Вкл');

  Future<void> load() async {
    _eventsSub ??= _events.receiveBroadcastStream().listen(_onEvent, onError: (_) {});
    _lifecycle ??= AppLifecycleListener(
      onShow: () => _setForeground(true),
      onHide: () => _setForeground(false),
    );
    enabled = await _storage.read(key: _enabledKey) == '1';
    notifyListeners();
    if (enabled) await _sync();
  }

  /// Включить или выключить. Возвращает текст ошибки для показа пользователю или null.
  Future<String?> setEnabled(bool on) async {
    if (!on) {
      enabled = false;
      error = null;
      notifyListeners();
      await _storage.write(key: _enabledKey, value: '0');
      await _sync();
      return null;
    }
    final mic = await Permission.microphone.request();
    if (!mic.isGranted) {
      return mic.isPermanentlyDenied
          ? 'Разрешите доступ к микрофону в настройках Android'
          : 'Без доступа к микрофону голосовое пробуждение не работает';
    }
    try {
      if (await _methods.invokeMethod<bool>('isModelReady') != true) {
        progress = 0;
        notifyListeners();
        await _methods.invokeMethod<bool>('downloadModel');
      }
    } on PlatformException catch (e) {
      return 'Не удалось загрузить голосовую модель: ${e.message}';
    } on MissingPluginException {
      return 'Голосовое пробуждение доступно только на Android';
    } finally {
      progress = null;
      notifyListeners();
    }
    enabled = true;
    error = null;
    notifyListeners();
    await _storage.write(key: _enabledKey, value: '1');
    await _sync();
    return error;
  }

  /// Временно освободить микрофон (например, на время диктовки или эфира). Ждёт, пока он освободится.
  Future<void> hold(String reason) {
    _holds.add(reason);
    return _sync();
  }

  /// Снять [hold] — снова слушать, если функция включена.
  Future<void> release(String reason) {
    _holds.remove(reason);
    return _sync();
  }

  void _setForeground(bool value) {
    _foreground = value;
    _sync();
  }

  /// Привести состояние микрофона к желаемому. Вызовы выстраиваются в очередь.
  Future<void> _sync() => _queue = _queue.then((_) => _apply()).catchError((_) {});

  Future<void> _apply() async {
    final want = enabled && _foreground && _holds.isEmpty;
    if (want == _listening) return;
    try {
      if (want) {
        if (!await Permission.microphone.isGranted) {
          error = 'Нет доступа к микрофону';
        } else {
          await _methods.invokeMethod<void>('start');
          _listening = true;
          error = null;
        }
      } else {
        await _methods.invokeMethod<void>('stop');
        _listening = false;
      }
    } on PlatformException catch (e) {
      _listening = false;
      error = e.message ?? 'Ошибка распознавания';
    } on MissingPluginException {
      _listening = false;
    }
    notifyListeners();
  }

  void _onEvent(dynamic event) {
    if (event is! Map) return;
    switch (event['type']) {
      case 'wake':
        if (_listening && _holds.isEmpty) _wakes.add(null);
      case 'progress':
        if (progress != null) {
          progress = (event['value'] as num).toDouble();
          notifyListeners();
        }
      case 'error':
        _listening = false;
        error = event['message'] as String?;
        notifyListeners();
    }
  }

  @override
  void dispose() {
    _eventsSub?.cancel();
    _lifecycle?.dispose();
    _wakes.close();
    super.dispose();
  }
}
