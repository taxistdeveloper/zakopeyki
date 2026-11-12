import 'dart:async';
import 'dart:io' show Platform;

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/app_config.dart';
import '../storage/secure_store.dart';
import 'api_exception.dart';

/// Успешный ответ: { "success": true, "data": {...}, "meta": {...}? }
class ApiResponse {
  const ApiResponse(this.data, this.meta);
  final Map<String, dynamic> data;
  final Map<String, dynamic> meta;
}

/// Ответ /auth/login, /auth/2fa, /auth/refresh.
class TokenPair {
  const TokenPair({required this.accessToken, required this.refreshToken});
  final String accessToken;
  final String refreshToken;

  static TokenPair? fromData(Map<String, dynamic> data) {
    final a = data['access_token'], r = data['refresh_token'];
    if (a is String && r is String) return TokenPair(accessToken: a, refreshToken: r);
    return null;
  }
}

/// HTTP-клиент мобильного API.
///
/// - Access-токен хранится только в памяти; refresh — в [SecureStore].
/// - При 401 один раз прозрачно обновляет токены (single-flight) и повторяет запрос.
/// - Если обновить не удалось — вызывает [onSessionExpired].
class ApiClient {
  ApiClient({required this._store, Dio? dio, String Function()? localeCode})
      : _localeCode = localeCode ?? (() => 'ru'),
        _dio = dio ??
            Dio(BaseOptions(
              baseUrl: AppConfig.apiBaseUrl + AppConfig.apiPrefix,
              connectTimeout: const Duration(seconds: 10),
              receiveTimeout: const Duration(seconds: 20),
              // статус разбираем сами, чтобы читать конверт ошибки
              validateStatus: (_) => true,
              responseType: ResponseType.json,
            ));

  final Dio _dio;
  final SecureStore _store;
  final String Function() _localeCode;
  String? _accessToken;
  Completer<bool>? _refreshing;

  /// Вызывается, когда сессию не удалось восстановить (refresh отклонён).
  void Function()? onSessionExpired;

  bool get hasAccessToken => _accessToken != null;

  Future<void> saveSession(TokenPair tokens) async {
    _accessToken = tokens.accessToken;
    await _store.writeRefreshToken(tokens.refreshToken);
  }

  Future<void> clearSession() async {
    _accessToken = null;
    await _store.writeRefreshToken(null);
  }

  Future<ApiResponse> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  /// multipart/form-data (фото объявлений). [build] создаёт форму заново — FormData нельзя
  /// отправить повторно, а запрос может повториться после обновления токена.
  Future<ApiResponse> postMultipart(String path, FormData Function() build, {void Function(int, int)? onProgress}) =>
      _send('POST', path, formBuilder: build, onProgress: onProgress);

  Future<ApiResponse> post(String path, {Map<String, dynamic>? body, bool auth = true}) =>
      _send('POST', path, body: body, auth: auth);

  /// Восстановить сессию по сохранённому refresh-токену. true — успешно.
  Future<bool> refreshSession() {
    final inFlight = _refreshing;
    if (inFlight != null) return inFlight.future;
    final completer = _refreshing = Completer<bool>();
    () async {
      try {
        final refresh = await _store.readRefreshToken();
        if (refresh == null || refresh.isEmpty) {
          completer.complete(false);
          return;
        }
        final res = await _send('POST', '/auth/refresh', body: {'refresh_token': refresh}, auth: false, retry: false);
        final tokens = TokenPair.fromData(res.data);
        if (tokens == null) {
          completer.complete(false);
          return;
        }
        await saveSession(tokens);
        lastRefreshData = res.data;
        completer.complete(true);
      } on ApiException catch (e) {
        if (e.isUnauthorized || e.statusCode == 403) {
          await clearSession();
        }
        completer.complete(false);
      } catch (_) {
        completer.complete(false);
      } finally {
        _refreshing = null;
      }
    }();
    return completer.future;
  }

  /// Данные последнего успешного refresh (содержат user) — для bootstrap авторизации.
  Map<String, dynamic>? lastRefreshData;

  Future<ApiResponse> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
    bool auth = true,
    bool retry = true,
    FormData Function()? formBuilder,
    void Function(int, int)? onProgress,
  }) async {
    final sentToken = auth ? _accessToken : null;
    final headers = <String, dynamic>{
      'Accept': 'application/json',
      'Accept-Language': _localeCode(),
      'X-Device-Id': await _store.deviceId(),
      'X-Platform': _platform(),
      'X-App-Version': AppConfig.appVersion,
      if (sentToken != null) 'Authorization': 'Bearer $sentToken',
    };

    Response<dynamic> response;
    try {
      response = await _dio.request<dynamic>(
        path,
        queryParameters: query,
        data: formBuilder != null ? formBuilder() : body,
        onSendProgress: onProgress,
        options: Options(
          method: method,
          headers: headers,
          contentType: formBuilder != null ? Headers.multipartFormDataContentType : Headers.jsonContentType,
          // загрузка фото по мобильной сети может идти долго
          sendTimeout: formBuilder != null ? const Duration(minutes: 3) : null,
        ),
      );
    } on DioException {
      throw ApiException.network;
    }

    final status = response.statusCode ?? 0;
    final json = response.data;
    if (json is! Map<String, dynamic>) {
      throw ApiException(code: 'bad_response', message: '', statusCode: status);
    }

    if (json['success'] == true) {
      final data = json['data'];
      final meta = json['meta'];
      return ApiResponse(
        data is Map<String, dynamic> ? data : <String, dynamic>{},
        meta is Map<String, dynamic> ? meta : <String, dynamic>{},
      );
    }

    final err = json['error'] is Map<String, dynamic> ? json['error'] as Map<String, dynamic> : const {};
    final error = ApiException(
      code: (err['code'] ?? 'unknown_error').toString(),
      message: (err['message'] ?? '').toString(),
      statusCode: status,
      fields: (err['fields'] is Map)
          ? (err['fields'] as Map).map((k, v) => MapEntry(k.toString(), v.toString()))
          : const {},
      data: json['data'] is Map<String, dynamic> ? json['data'] as Map<String, dynamic> : const {},
    );

    // Запрос ушёл с токеном и получил 401 → обновить сессию (один refresh на все параллельные запросы)
    final canRefresh = auth && retry && status == 401 && sentToken != null;
    if (canRefresh) {
      final alreadyRefreshed = _accessToken != null && _accessToken != sentToken;
      if (!alreadyRefreshed) {
        if (_accessToken == sentToken) _accessToken = null;
        if (!await refreshSession()) {
          onSessionExpired?.call();
          throw error;
        }
      }
      return _send(method, path,
          query: query, body: body, auth: auth, retry: false, formBuilder: formBuilder, onProgress: onProgress);
    }
    throw error;
  }

  static String _platform() {
    try {
      if (Platform.isIOS) return 'ios';
      if (Platform.isAndroid) return 'android';
    } catch (_) {}
    return 'other';
  }
}

/// Текущий язык интерфейса для заголовка Accept-Language (задаётся из localeProvider).
String currentLocaleCode = 'ru';

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(store: ref.watch(secureStoreProvider), localeCode: () => currentLocaleCode);
});
