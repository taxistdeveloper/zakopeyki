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
  final Map<String, dynamic> body;

  ApiException(this.status, this.code, this.message, [this.body = const {}]);

  bool get isStub => code == 'stub';
  bool get isUnauthorized => status == 401;
  bool get isNetwork => status == 0;

  @override
  String toString() => message;
}

/// Результат входа: либо токен, либо нужен 2FA-код.
class LoginResult {
  final String? token;
  final AppUser? user;
  final String? challenge;

  LoginResult({this.token, this.user, this.challenge});

  bool get needsTwoFactor => challenge != null;
}

class Api {
  Api({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;
  String? token;

  static const _timeout = Duration(seconds: 25);

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'X-Zk-App': kAppMarker,
        'X-Mobile-Token': ?token,
      };

  Future<Map<String, dynamic>> _get(String path, [Map<String, String>? query]) async {
    var uri = siteUri('$kApiPrefix$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: query);
    }
    return _send(() => _client.get(uri, headers: _headers));
  }

  Future<Map<String, dynamic>> _post(String path, [Map<String, dynamic>? body]) {
    final uri = siteUri('$kApiPrefix$path');
    return _send(() => _client.post(
          uri,
          headers: {..._headers, 'Content-Type': 'application/json'},
          body: jsonEncode(body ?? const {}),
        ));
  }

  Future<Map<String, dynamic>> _send(Future<http.Response> Function() call) async {
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

    Map<String, dynamic> data;
    try {
      data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    } catch (_) {
      if (res.statusCode < 400) {
        // Вместо JSON пришла HTML-страница: на сервере нет /api/mobile/v1.
        throw ApiException(res.statusCode, 'no_api', 'Сервер пока не поддерживает приложение. Попробуйте позже.');
      }
      throw ApiException(res.statusCode, 'bad_response', 'Ошибка сервера (${res.statusCode})');
    }

    if (res.statusCode >= 400 || data['ok'] == false) {
      final code = data['error']?.toString() ?? 'error';
      throw ApiException(res.statusCode, code, _humanError(code), data);
    }
    return data;
  }

  String _humanError(String code) => switch (code) {
        'unauthorized' => 'Войдите в аккаунт',
        'not_found' => 'Объявление не найдено',
        'stub' => 'Сайт скоро откроется',
        _ => code,
      };

  // --- endpoints ---------------------------------------------------------

  Future<AppConfig> config() async => AppConfig.fromJson(await _get('/config'));

  Future<LoginResult> login(String email, String password, String device) async {
    final d = await _post('/auth/login', {'email': email, 'password': password, 'device': device});
    return _loginResult(d);
  }

  Future<LoginResult> twoFactor(String challenge, String code, String device) async {
    final d = await _post('/auth/2fa', {'challenge': challenge, 'code': code, 'device': device});
    return _loginResult(d);
  }

  LoginResult _loginResult(Map<String, dynamic> d) {
    if (d['two_factor_required'] == true) {
      return LoginResult(challenge: d['challenge'] as String);
    }
    return LoginResult(
      token: d['token'] as String,
      user: AppUser.fromJson(d['user'] as Map<String, dynamic>),
    );
  }

  Future<void> logout() async {
    await _post('/auth/logout');
  }

  Future<AppUser> me() async {
    final d = await _get('/me');
    return AppUser.fromJson(d['user'] as Map<String, dynamic>);
  }

  Future<ProductPage> products({
    String? type,
    String? query,
    String? category,
    int page = 1,
    int perPage = 20,
  }) async {
    final d = await _get('/products', {
      'type': ?type,
      if (query != null && query.isNotEmpty) 'q': query,
      'category': ?category,
      'page': '$page',
      'per_page': '$perPage',
    });
    return ProductPage.fromJson(d);
  }

  Future<ProductDetail> product(int id) async => ProductDetail.fromJson(await _get('/products/$id'));

  Future<List<ProductCard>> favorites() async {
    final d = await _get('/favorites');
    return ((d['items'] as List?) ?? [])
        .map((e) => ProductCard.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<bool> toggleFavorite(int id) async {
    final d = await _post('/favorites/$id/toggle');
    return d['favorited'] == true;
  }
}
