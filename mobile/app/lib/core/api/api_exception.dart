/// Ошибка API в едином формате сервера:
/// { "success": false, "error": { "code", "message", "fields"? } }
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.statusCode,
    this.fields = const {},
    this.data = const {},
  });

  /// Машинный код (snake_case) — используется для логики клиента.
  final String code;

  /// Локализованный сервером текст — можно показывать пользователю.
  final String message;
  final int? statusCode;
  final Map<String, String> fields;

  /// Доп. данные ошибки (например, блокеры удаления аккаунта).
  final Map<String, dynamic> data;

  bool get isNetwork => code == 'network_error';
  bool get isUnauthorized => statusCode == 401;
  bool get isSiteClosed => code == 'site_closed';

  static const network = ApiException(code: 'network_error', message: '');

  @override
  String toString() => 'ApiException($code, $statusCode): $message';
}
