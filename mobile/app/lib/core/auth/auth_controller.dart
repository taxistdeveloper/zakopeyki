import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import 'user.dart';

sealed class AuthState {
  const AuthState();
}

/// Идёт восстановление сессии при старте.
class AuthUnknown extends AuthState {
  const AuthUnknown();
}

class AuthGuest extends AuthState {
  const AuthGuest({this.sessionExpired = false});
  final bool sessionExpired;
}

class AuthAuthenticated extends AuthState {
  const AuthAuthenticated(this.user);
  final AppUser user;
}

/// Результат шага входа: либо вошли, либо нужен код 2FA.
sealed class LoginResult {
  const LoginResult();
}

class LoginSuccess extends LoginResult {
  const LoginSuccess();
}

class LoginNeedsTwoFactor extends LoginResult {
  const LoginNeedsTwoFactor(this.challengeToken);
  final String challengeToken;
}

class AuthController extends Notifier<AuthState> {
  late final ApiClient _api;

  @override
  AuthState build() {
    _api = ref.read(apiClientProvider);
    _api.onSessionExpired = () {
      if (state is AuthAuthenticated) state = const AuthGuest(sessionExpired: true);
    };
    Future.microtask(_restore);
    return const AuthUnknown();
  }

  Future<void> _restore() async {
    final ok = await _api.refreshSession();
    final user = _api.lastRefreshData?['user'];
    if (ok && user is Map<String, dynamic>) {
      state = AuthAuthenticated(AppUser.fromJson(user));
    } else {
      state = const AuthGuest();
    }
  }

  Future<LoginResult> login({required String email, required String password}) async {
    final res = await _api.post('/auth/login', body: {'email': email, 'password': password, 'device_name': _deviceName()}, auth: false);
    if (res.data['two_factor_required'] == true) {
      return LoginNeedsTwoFactor(res.data['challenge_token'] as String);
    }
    await _applySession(res.data);
    return const LoginSuccess();
  }

  /// Регистрация по email. Возвращает начисленные бонусные баллы (если есть).
  Future<int?> register({
    required String name,
    required String email,
    required String password,
    String? phone,
    required bool acceptOffer,
  }) async {
    final res = await _api.post('/auth/register', auth: false, body: {
      'name': name,
      'email': email,
      'password': password,
      if (phone != null && phone.isNotEmpty) 'phone': phone,
      'accept_offer': acceptOffer,
      'device_name': _deviceName(),
    });
    await _applySession(res.data);
    return (res.data['registration_bonus_points'] as num?)?.toInt();
  }

  /// Запросить письмо для сброса пароля. Возвращает текст сервера (одинаковый для любого email).
  Future<String> forgotPassword(String email) async {
    final res = await _api.post('/auth/forgot-password', auth: false, body: {'email': email});
    return (res.data['message'] ?? '').toString();
  }

  /// Sign in with Apple. Одноразовый nonce: Apple получает его sha256, сервер сверяет с токеном.
  /// null — пользователь отменил вход.
  Future<LoginResult?> signInWithApple() async {
    final rawNonce = _randomNonce();
    final AuthorizationCredentialAppleID credential;
    try {
      credential = await SignInWithApple.getAppleIDCredential(
        scopes: [AppleIDAuthorizationScopes.email, AppleIDAuthorizationScopes.fullName],
        nonce: sha256.convert(utf8.encode(rawNonce)).toString(),
      );
    } on SignInWithAppleAuthorizationException catch (e) {
      if (e.code == AuthorizationErrorCode.canceled) return null;
      throw const ApiException(code: 'apple_unavailable', message: '');
    } on SignInWithAppleException {
      throw const ApiException(code: 'apple_unavailable', message: '');
    }
    final token = credential.identityToken;
    if (token == null) throw const ApiException(code: 'apple_unavailable', message: '');

    final res = await _api.post('/auth/apple', auth: false, body: {
      'identity_token': token,
      'raw_nonce': rawNonce,
      'given_name': ?credential.givenName,
      'family_name': ?credential.familyName,
      'device_name': _deviceName(),
    });
    if (res.data['two_factor_required'] == true) {
      return LoginNeedsTwoFactor(res.data['challenge_token'] as String);
    }
    await _applySession(res.data);
    return const LoginSuccess();
  }

  static String _randomNonce([int length = 32]) {
    final random = Random.secure();
    return base64UrlEncode(List<int>.generate(length, (_) => random.nextInt(256))).replaceAll('=', '');
  }

  Future<void> verifyTwoFactor({required String challengeToken, required String code}) async {
    final res = await _api.post('/auth/2fa', body: {'challenge_token': challengeToken, 'code': code}, auth: false);
    await _applySession(res.data);
  }

  /// Аккаунт удалён на сервере (сессии там уже отозваны) — очистить локальную сессию.
  Future<void> onAccountDeleted() async {
    await _api.clearSession();
    state = const AuthGuest();
  }

  Future<void> logout({bool everywhere = false}) async {
    try {
      if (everywhere) {
        await _api.post('/auth/logout-all');
      } else {
        await _api.post('/auth/logout', body: {}, auth: true);
      }
    } on ApiException {
      // локальный выход выполняется в любом случае
    }
    await _api.clearSession();
    state = const AuthGuest();
  }

  Future<void> _applySession(Map<String, dynamic> data) async {
    final tokens = TokenPair.fromData(data);
    final user = data['user'];
    if (tokens == null || user is! Map<String, dynamic>) {
      throw const ApiException(code: 'bad_response', message: '');
    }
    await _api.saveSession(tokens);
    state = AuthAuthenticated(AppUser.fromJson(user));
  }

  String _deviceName() => 'Zakopeyki app';
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);
