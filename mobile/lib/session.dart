import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:webview_flutter/webview_flutter.dart';

import 'api.dart';
import 'config.dart';
import 'models.dart';

/// Состояние приложения: токен устройства, пользователь, конфиг сайта.
class Session extends ChangeNotifier {
  Session({Api? api, FlutterSecureStorage? storage})
      : api = api ?? Api(),
        _storage = storage ?? const FlutterSecureStorage();

  final Api api;
  final FlutterSecureStorage _storage;
  final WebViewCookieManager _cookies = WebViewCookieManager();

  static const _tokenKey = 'device_token';

  AppConfig? config;
  AppUser? user;
  bool loading = true;
  String? startupError;

  bool get loggedIn => api.token != null && user != null;
  bool get stubLocked => (config?.stubMode ?? false) && !(config?.siteAccess ?? false);

  String get deviceName =>
      'Android ${defaultTargetPlatform.name} · $kAppMarker';

  Future<void> start() async {
    loading = true;
    startupError = null;
    notifyListeners();
    try {
      api.token = await _storage.read(key: _tokenKey);
      await _syncWebCookie();
      config = await api.config();
      user = config!.user;
      if (api.token != null && user == null) {
        // Токен отозван или протух
        await _clearToken();
      }
    } on ApiException catch (e) {
      startupError = e.message;
    } catch (e) {
      startupError = 'Не удалось загрузить приложение';
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Вход по паролю. Возвращает challenge, если нужен код 2FA.
  Future<String?> login(String email, String password) async {
    final r = await api.login(email, password, deviceName);
    if (r.needsTwoFactor) return r.challenge;
    await _applyLogin(r);
    return null;
  }

  Future<void> verifyTwoFactor(String challenge, String code) async {
    final r = await api.twoFactor(challenge, code, deviceName);
    await _applyLogin(r);
  }

  /// После регистрации / входа на сайте внутри WebView: страница сама сходила
  /// на /auth/session-token (same-origin fetch с её cookie) и передала ответ сюда.
  Future<void> adoptWebLogin(Map<String, dynamic> response) async {
    final token = response['token'];
    final user = response['user'];
    if (token is! String || user is! Map<String, dynamic>) return;
    await _applyLogin(LoginResult(token: token, user: AppUser.fromJson(user)));
  }

  Future<void> _applyLogin(LoginResult r) async {
    api.token = r.token;
    user = r.user;
    await _storage.write(key: _tokenKey, value: r.token);
    await _syncWebCookie();
    try {
      config = await api.config();
    } catch (_) {}
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.logout();
    } catch (_) {
      // токен всё равно удаляем локально
    }
    await _clearToken();
    await _cookies.clearCookies();
    try {
      config = await api.config();
    } catch (_) {}
    notifyListeners();
  }

  Future<void> refreshUser() async {
    if (api.token == null) return;
    try {
      user = await api.me();
      notifyListeners();
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await _clearToken();
        notifyListeners();
      }
    }
  }

  Future<void> _clearToken() async {
    api.token = null;
    user = null;
    await _storage.delete(key: _tokenKey);
    await _syncWebCookie();
  }

  /// Кладём токен в cookie WebView — сервер поднимет по нему сессию.
  Future<void> _syncWebCookie() async {
    final site = Uri.parse(kSiteUrl);
    await _cookies.setCookie(WebViewCookie(
      name: kTokenCookie,
      value: api.token ?? '',
      domain: site.host,
      path: '/',
    ));
  }
}
