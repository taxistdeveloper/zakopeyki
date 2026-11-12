import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:zakopeyki/core/storage/secure_store.dart';

class MemorySecureStore implements SecureStore {
  String? refresh;
  String? locale;

  @override
  Future<String> deviceId() async => 'test-device';

  @override
  Future<String?> readRefreshToken() async => refresh;

  @override
  Future<void> writeRefreshToken(String? token) async => refresh = token;

  @override
  Future<String?> readLocale() async => locale;

  @override
  Future<void> writeLocale(String? code) async => locale = code;
}

typedef Handler = ({int status, Map<String, dynamic> body}) Function(RequestOptions options);

/// Подменяет сеть Dio: отвечает JSON-ом из [handler] и записывает запросы.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.handler);
  Handler handler;
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    final r = handler(options);
    return ResponseBody.fromString(
      jsonEncode(r.body),
      r.status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Dio fakeDio(FakeAdapter adapter) => Dio(BaseOptions(
      baseUrl: 'https://test.local/api/mobile/v1',
      validateStatus: (_) => true,
      responseType: ResponseType.json,
    ))
      ..httpClientAdapter = adapter;

Map<String, dynamic> ok(Map<String, dynamic> data, [Map<String, dynamic>? meta]) =>
    {'success': true, 'data': data, 'meta': ?meta};

Map<String, dynamic> apiError(String code, [String message = '']) => {
      'success': false,
      'error': {'code': code, 'message': message},
    };
