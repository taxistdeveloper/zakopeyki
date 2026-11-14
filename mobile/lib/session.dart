import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:webview_flutter/webview_flutter.dart';

import 'api.dart';
import 'config.dart';
import 'models.dart';

/// Состояние приложения: токены, пользователь, конфиг сайта.
///
/// Access-токен (15 мин) живёт только в памяти, refresh-токен (60 дней, ротация) — в Keystore.
class Session extends ChangeNotifier {
  Session({Api? api, FlutterSecureStorage? storage})
      : api = api ?? Api(),
        _storage = storage ?? const FlutterSecureStorage() {
    this.api.onUnauthorized = _refreshAccess;
  }

  final Api api;
  final FlutterSecureStorage _storage;

  static const _refreshKey = 'refresh_token';
  static const _deviceKey = 'device_id';

  String? _refreshToken;
  Future<bool>? _refreshing;

  AppConfig? config;
  AppUser? user;
  bool loading = true;
  String? startupError;

  /// Есть ли у аккаунта доступ к каталогу, пока сайт в режиме «скоро открытие».
  bool siteAccess = false;

  bool get loggedIn => user != null && _refreshToken != null;
  bool get stubLocked => (config?.stubMode ?? false) && !siteAccess;

  String get deviceName => 'Android · $kAppMarker';

  Future<void> start() async {
    loading = true;
    startupError = null;
    notifyListeners();
    try {
      await _ensureDeviceId();
      _refreshToken ??= await _storage.read(key: _refreshKey);
      config = await api.config();
      if (_refreshToken != null && user == null) {
        await _refreshAccess();
      }
      await _loadCategories();
      await _checkSiteAccess();
      refreshCartCount();
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
    await _ensureDeviceId();
    final r = await api.login(email, password, deviceName);
    if (r.needsTwoFactor) return r.challenge;
    await _applySession(r.session!);
    return null;
  }

  /// Регистрация по email: сразу входит в новый аккаунт.
  Future<void> register({
    required String name,
    required String email,
    required String password,
    String? phone,
  }) async {
    await _ensureDeviceId();
    final s = await api.register(
      name: name,
      email: email,
      password: password,
      phone: phone,
      deviceName: deviceName,
    );
    await _applySession(s);
  }

  /// Количество товаров в корзине (бейдж на вкладке).
  int cartCount = 0;

  void setCartCount(int count) {
    if (count == cartCount) return;
    cartCount = count;
    notifyListeners();
  }

  Future<void> refreshCartCount() async {
    if (!loggedIn || stubLocked) {
      setCartCount(0);
      return;
    }
    try {
      setCartCount((await api.cart()).count);
    } catch (_) {}
  }

  Future<void>? _googleInit;

  /// Вход через Google. Возвращает challenge, если на аккаунте включена 2FA;
  /// null — вошли; бросает [GoogleCancelled], если пользователь закрыл окно выбора аккаунта.
  Future<String?> loginWithGoogle() async {
    await _ensureDeviceId();
    final google = GoogleSignIn.instance;
    await (_googleInit ??= google.initialize(serverClientId: kGoogleServerClientId));
    GoogleSignInAccount account;
    try {
      account = await google.authenticate();
    } on GoogleSignInException catch (e) {
      if (e.code == GoogleSignInExceptionCode.canceled ||
          e.code == GoogleSignInExceptionCode.interrupted) {
        throw GoogleCancelled();
      }
      throw ApiException(0, 'google_failed', 'Не удалось войти через Google (${e.code.name})');
    }
    final idToken = account.authentication.idToken;
    if (idToken == null) {
      throw ApiException(0, 'google_failed', 'Google не вернул токен входа');
    }
    final r = await api.google(idToken, deviceName);
    if (r.needsTwoFactor) return r.challenge;
    await _applySession(r.session!);
    return null;
  }

  Future<void> verifyTwoFactor(String challenge, String code) async {
    final r = await api.twoFactor(challenge, code);
    await _applySession(r.session!);
  }

  Future<void> _applySession(AuthSession s) async {
    api.accessToken = s.accessToken;
    _refreshToken = s.refreshToken;
    user = s.user;
    await _storage.write(key: _refreshKey, value: s.refreshToken);
    await _checkSiteAccess();
    notifyListeners();
    refreshCartCount();
  }

  Future<void> logout() async {
    try {
      await api.logout(_refreshToken);
    } catch (_) {
      // локально выходим в любом случае
    }
    await _clear();
    cartCount = 0;
    await WebViewCookieManager().clearCookies();
    if (_googleInit != null) {
      // чтобы при следующем входе Google снова предложил выбрать аккаунт
      await GoogleSignIn.instance.signOut().catchError((_) {});
    }
    siteAccess = false;
    await _checkSiteAccess();
    notifyListeners();
  }

  Future<void> refreshUser() async {
    if (!loggedIn) return;
    try {
      user = await api.me();
      notifyListeners();
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await _clear();
        notifyListeners();
      }
    }
  }

  /// Обновляет access-токен по refresh-токену (один запрос на все параллельные 401).
  Future<bool> _refreshAccess() {
    return _refreshing ??= _doRefresh().whenComplete(() => _refreshing = null);
  }

  Future<bool> _doRefresh() async {
    final token = _refreshToken;
    if (token == null) return false;
    try {
      final s = await api.refresh(token, deviceName);
      api.accessToken = s.accessToken;
      _refreshToken = s.refreshToken;
      user = s.user;
      await _storage.write(key: _refreshKey, value: s.refreshToken);
      return true;
    } on ApiException catch (e) {
      if (e.isNetwork) rethrow;
      // refresh отозван / истёк / аккаунт заблокирован
      await _clear();
      notifyListeners();
      return false;
    }
  }

  Future<void> _clear() async {
    api.accessToken = null;
    _refreshToken = null;
    user = null;
    await _storage.delete(key: _refreshKey);
  }

  Future<void> _loadCategories() async {
    final c = config;
    if (c == null || c.categories.isNotEmpty) return;
    try {
      c.categories = await api.categories();
    } catch (_) {
      // фильтр по категориям просто будет пустым
    }
  }

  /// В режиме заглушки каталог доступен только admin / site_access — проверяем пробным запросом.
  Future<void> _checkSiteAccess() async {
    if (!(config?.stubMode ?? false)) {
      siteAccess = true;
      return;
    }
    if (!loggedIn) {
      siteAccess = false;
      return;
    }
    try {
      await api.products(limit: 1);
      siteAccess = true;
    } on ApiException catch (e) {
      if (e.isStub) siteAccess = false;
    }
  }

  Future<void> _ensureDeviceId() async {
    if (api.deviceId.isNotEmpty) return;
    var id = await _storage.read(key: _deviceKey);
    if (id == null || id.isEmpty) {
      final rnd = Random.secure();
      id = List.generate(16, (_) => rnd.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
      await _storage.write(key: _deviceKey, value: id);
    }
    api.deviceId = id;
  }
}

/// Пользователь закрыл окно выбора Google-аккаунта.
class GoogleCancelled implements Exception {}
