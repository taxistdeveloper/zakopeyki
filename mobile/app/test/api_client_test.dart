import 'package:flutter_test/flutter_test.dart';
import 'package:zakopeyki/core/api/api_client.dart';
import 'package:zakopeyki/core/api/api_exception.dart';

import 'support/fakes.dart';

void main() {
  late MemorySecureStore store;

  setUp(() => store = MemorySecureStore());

  test('parses success envelope with meta', () async {
    final adapter = FakeAdapter((o) => (status: 200, body: ok({'items': []}, {'next_cursor': 'abc'})));
    final api = ApiClient(store: store, dio: fakeDio(adapter));
    final res = await api.get('/products');
    expect(res.data['items'], isEmpty);
    expect(res.meta['next_cursor'], 'abc');
    expect(adapter.requests.single.headers['X-Device-Id'], 'test-device');
  });

  test('error envelope becomes ApiException with server code and message', () async {
    final adapter = FakeAdapter((o) => (status: 401, body: apiError('bad_credentials', 'Неверный email или пароль.')));
    final api = ApiClient(store: store, dio: fakeDio(adapter));
    await expectLater(
      api.post('/auth/login', body: {}, auth: false),
      throwsA(isA<ApiException>()
          .having((e) => e.code, 'code', 'bad_credentials')
          .having((e) => e.statusCode, 'status', 401)
          .having((e) => e.message, 'message', 'Неверный email или пароль.')),
    );
  });

  test('expired access token is refreshed once and the request retried', () async {
    var refreshCalls = 0;
    final adapter = FakeAdapter((o) {
      if (o.path == '/auth/refresh') {
        refreshCalls++;
        return (status: 200, body: ok({'access_token': 'A2', 'refresh_token': 'R2', 'user': {'id': 1}}));
      }
      final auth = o.headers['Authorization'];
      return auth == 'Bearer A2'
          ? (status: 200, body: ok({'user': {'id': 1}}))
          : (status: 401, body: apiError('token_expired'));
    });
    final api = ApiClient(store: store, dio: fakeDio(adapter));
    await api.saveSession(const TokenPair(accessToken: 'A1', refreshToken: 'R1'));

    final results = await Future.wait([api.get('/me'), api.get('/me'), api.get('/me')]);

    expect(results, hasLength(3));
    expect(refreshCalls, 1, reason: 'concurrent 401s must share a single refresh');
    expect(store.refresh, 'R2', reason: 'rotated refresh token persisted');
  });

  test('rejected refresh clears session and reports expiry', () async {
    var expired = false;
    final adapter = FakeAdapter((o) => o.path == '/auth/refresh'
        ? (status: 401, body: apiError('invalid_refresh_token'))
        : (status: 401, body: apiError('session_revoked')));
    final api = ApiClient(store: store, dio: fakeDio(adapter))..onSessionExpired = () => expired = true;
    await api.saveSession(const TokenPair(accessToken: 'A1', refreshToken: 'R1'));

    await expectLater(api.get('/me'), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'session_revoked')));
    expect(expired, isTrue);
    expect(store.refresh, isNull);
    expect(api.hasAccessToken, isFalse);
  });

  test('network failure maps to network_error', () async {
    final api = ApiClient(
      store: store,
      dio: fakeDio(FakeAdapter((o) => throw Exception('offline'))),
    );
    await expectLater(api.get('/config'), throwsA(isA<ApiException>().having((e) => e.isNetwork, 'isNetwork', true)));
  });

  test('refreshSession without stored token returns false without network', () async {
    final adapter = FakeAdapter((o) => (status: 500, body: apiError('x')));
    final api = ApiClient(store: store, dio: fakeDio(adapter));
    expect(await api.refreshSession(), isFalse);
    expect(adapter.requests, isEmpty);
  });
}
