/// Настройки сборки. Передаются через --dart-define, секретов здесь нет и быть не должно.
///
///   flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8137
class AppConfig {
  const AppConfig._();

  /// Базовый URL сайта (без /api/...). Для production — https://zakopeyki.kz
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://zakopeyki.kz',
  );

  static const String apiPrefix = '/api/mobile/v1';

  static const String appVersion = String.fromEnvironment('APP_VERSION', defaultValue: '1.0.0');
}
