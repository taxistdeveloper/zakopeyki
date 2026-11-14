import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'config.dart';
import 'models.dart';

class ApiException implements Exception {
  final int status;
  final String code;
  final String message;
  final Map<String, String> fields;

  ApiException(this.status, this.code, this.message, [this.fields = const {}]);

  /// Сайт в режиме «скоро открытие», у аккаунта нет раннего доступа.
  bool get isStub => code == 'site_closed';
  bool get isUnauthorized => status == 401;
  bool get isNetwork => status == 0;

  @override
  String toString() => message;
}

/// Пара токенов + пользователь (ответ login / 2fa / refresh / register).
class AuthSession {
  final String accessToken;
  final String refreshToken;
  final AppUser user;

  AuthSession(this.accessToken, this.refreshToken, this.user);
}

/// Результат входа: либо сессия, либо нужен 2FA-код.
class LoginResult {
  final AuthSession? session;
  final String? challenge;

  LoginResult({this.session, this.challenge});

  bool get needsTwoFactor => challenge != null;
}

/// Клиент /api/mobile/v1. Ответы: {success, data, meta?} / {success:false, error:{code,message,fields?}}.
class Api {
  Api({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  /// Access-токен (15 мин) — только в памяти.
  String? accessToken;

  /// Идентификатор установки, обязателен для входа и refresh.
  String deviceId = '';

  /// Вызывается при 401 на запросе с токеном; должен обновить accessToken. true — повторить запрос.
  Future<bool> Function()? onUnauthorized;

  static const _timeout = Duration(seconds: 25);

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Accept-Language': 'ru',
        'X-Device-Id': deviceId,
        'X-Platform': 'android',
        'X-App-Version': kAppVersion,
        if (accessToken != null) 'Authorization': 'Bearer $accessToken',
      };

  Future<_Envelope> _get(String path, [Map<String, String>? query]) {
    var uri = siteUri('$kApiPrefix$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: query);
    }
    return _send(() => _client.get(uri, headers: _headers));
  }

  Future<_Envelope> _post(String path, [Map<String, dynamic>? body, bool retryAuth = true]) {
    final uri = siteUri('$kApiPrefix$path');
    return _send(
      () => _client.post(
        uri,
        headers: {..._headers, 'Content-Type': 'application/json'},
        body: jsonEncode(body ?? const {}),
      ),
      retryAuth: retryAuth,
    );
  }

  Future<_Envelope> _send(Future<http.Response> Function() call, {bool retryAuth = true}) async {
    final hadToken = accessToken != null;
    try {
      return await _sendOnce(call);
    } on ApiException catch (e) {
      final refresher = onUnauthorized;
      if (e.isUnauthorized && hadToken && retryAuth && refresher != null && await refresher()) {
        return _sendOnce(call);
      }
      rethrow;
    }
  }

  Future<_Envelope> _sendOnce(Future<http.Response> Function() call) async {
    http.Response res;
    try {
      res = await call().timeout(_timeout);
    } on SocketException {
      throw ApiException(0, 'network', 'Нет соединения с интернетом');
    } on TimeoutException {
      throw ApiException(0, 'network', 'Сервер не отвечает, попробуйте позже');
    } on http.ClientException {
      throw ApiException(0, 'network', 'Нет соединения с сервером');
    }

    Map<String, dynamic> body;
    try {
      body = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    } catch (_) {
      if (res.statusCode < 400) {
        // Вместо JSON пришла HTML-страница: на сервере нет /api/mobile/v1.
        throw ApiException(res.statusCode, 'no_api', 'Сервер пока не поддерживает приложение. Попробуйте позже.');
      }
      throw ApiException(res.statusCode, 'bad_response', 'Ошибка сервера (${res.statusCode})');
    }

    if (res.statusCode >= 400 || body['success'] != true) {
      final err = (body['error'] as Map?)?.cast<String, dynamic>() ?? const {};
      final code = err['code']?.toString() ?? 'error';
      final fields = ((err['fields'] as Map?) ?? const {})
          .map((k, v) => MapEntry(k.toString(), v.toString()));
      final message = err['message']?.toString();
      throw ApiException(
        res.statusCode,
        code,
        message != null && message.isNotEmpty ? message : 'Ошибка сервера (${res.statusCode})',
        fields,
      );
    }
    final data = body['data'];
    return _Envelope(
      data is Map<String, dynamic> ? data : const {},
      (body['meta'] as Map?)?.cast<String, dynamic>() ?? const {},
    );
  }

  // --- auth --------------------------------------------------------------

  Future<LoginResult> login(String email, String password, String deviceName) async {
    final e = await _post('/auth/login', {
      'email': email,
      'password': password,
      'device_id': deviceId,
      'device_name': deviceName,
      'platform': 'android',
    }, false);
    return _loginResult(e.data);
  }

  Future<LoginResult> twoFactor(String challenge, String code) async {
    final e = await _post('/auth/2fa', {
      'challenge_token': challenge,
      'code': code,
      'device_id': deviceId,
    }, false);
    return _loginResult(e.data);
  }

  /// Вход / регистрация через Google: ID-токен из нативного Google Sign-In.
  Future<LoginResult> google(String idToken, String deviceName) async {
    final e = await _post('/auth/google', {
      'id_token': idToken,
      'device_id': deviceId,
      'device_name': deviceName,
      'platform': 'android',
    }, false);
    return _loginResult(e.data);
  }

  Future<AuthSession> refresh(String refreshToken, String deviceName) async {
    final e = await _post('/auth/refresh', {
      'refresh_token': refreshToken,
      'device_id': deviceId,
      'device_name': deviceName,
      'platform': 'android',
    }, false);
    return _session(e.data);
  }

  Future<void> logout(String? refreshToken) async {
    await _post('/auth/logout', {'refresh_token': ?refreshToken}, false);
  }

  LoginResult _loginResult(Map<String, dynamic> d) {
    if (d['two_factor_required'] == true) {
      return LoginResult(challenge: d['challenge_token'] as String);
    }
    return LoginResult(session: _session(d));
  }

  AuthSession _session(Map<String, dynamic> d) => AuthSession(
        d['access_token'] as String,
        d['refresh_token'] as String,
        AppUser.fromJson(d['user'] as Map<String, dynamic>),
      );

  Future<AppUser> me() async {
    final e = await _get('/me');
    return AppUser.fromJson(e.data['user'] as Map<String, dynamic>);
  }

  // --- catalog -----------------------------------------------------------

  Future<AppConfig> config() async => AppConfig.fromJson((await _get('/config')).data);

  Future<List<Category>> categories() async {
    final e = await _get('/categories');
    return ((e.data['categories'] as List?) ?? [])
        .map((c) => Category.fromJson(c as Map<String, dynamic>))
        .toList();
  }

  Future<ProductPage> products({
    String? type,
    String? query,
    String? category,
    String? cursor,
    int limit = 20,
  }) async {
    final e = await _get('/products', {
      'type': ?type,
      if (query != null && query.length >= 2) 'q': query,
      if (category != null && category.isNotEmpty) 'category': category,
      'cursor': ?cursor,
      'limit': '$limit',
    });
    return ProductPage(
      items: _cards(e.data['items']),
      nextCursor: e.meta['next_cursor'] as String?,
    );
  }

  Future<ProductDetail> product(int id) async => ProductDetail.fromJson((await _get('/products/$id')).data);

  Future<List<ProductCard>> favorites() async => _cards((await _get('/me/favorites')).data['items']);

  Future<bool> toggleFavorite(int id) async {
    final e = await _post('/products/$id/favorite');
    return e.data['is_favorite'] == true;
  }

  List<ProductCard> _cards(Object? list) => ((list as List?) ?? [])
      .map((e) => ProductCard.fromJson(e as Map<String, dynamic>))
      .toList();
}

class _Envelope {
  final Map<String, dynamic> data;
  final Map<String, dynamic> meta;
  _Envelope(this.data, this.meta);
}
