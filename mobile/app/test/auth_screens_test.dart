import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';
import 'package:zakopeyki/core/api/api_client.dart';
import 'package:zakopeyki/core/storage/secure_store.dart';
import 'package:zakopeyki/features/auth/forgot_password_screen.dart';
import 'package:zakopeyki/features/auth/login_screen.dart';
import 'package:zakopeyki/features/auth/register_screen.dart';
import 'package:zakopeyki/l10n/app_localizations.dart';

import 'support/fakes.dart';

Widget harness(FakeAdapter adapter, String initial) {
  final store = MemorySecureStore();
  final router = GoRouter(initialLocation: initial, routes: [
    GoRoute(path: '/', builder: (_, _) => const Scaffold(body: Text('HOME'))),
    GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
    GoRoute(path: '/register', builder: (_, _) => const RegisterScreen()),
    GoRoute(path: '/forgot-password', builder: (_, _) => const ForgotPasswordScreen()),
  ]);
  return ProviderScope(
    overrides: [
      secureStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWithValue(ApiClient(store: store, dio: fakeDio(adapter))),
    ],
    child: MaterialApp.router(
      locale: const Locale('ru'),
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      routerConfig: router,
    ),
  );
}

Map<String, dynamic> body(FakeAdapter a, String path) =>
    a.requests.lastWhere((r) => r.path == path).data as Map<String, dynamic>;

Future<void> fillRegister(WidgetTester tester) async {
  final fields = find.byType(TextFormField);
  await tester.enterText(fields.at(0), 'Айгерим');
  await tester.enterText(fields.at(1), 'aigerim@example.kz');
  await tester.enterText(fields.at(2), 'Secret123');
}

void main() {
  testWidgets('registration requires accepting the offer before calling the server', (tester) async {
    final adapter = FakeAdapter((o) => (status: 200, body: ok({})));
    await tester.pumpWidget(harness(adapter, '/register'));
    await tester.pumpAndSettle();
    await fillRegister(tester);

    await tester.tap(find.widgetWithText(FilledButton, 'Зарегистрироваться'));
    await tester.pumpAndSettle();

    expect(find.text('Примите пользовательское соглашение'), findsOneWidget);
    expect(adapter.requests.where((r) => r.path == '/auth/register'), isEmpty);
  });

  testWidgets('server field error is shown under the field', (tester) async {
    final adapter = FakeAdapter((o) => o.path == '/auth/register'
        ? (
            status: 409,
            body: {
              'success': false,
              'error': {
                'code': 'email_taken',
                'message': 'Этот email уже зарегистрирован.',
                'fields': {'email': 'Этот email уже зарегистрирован.'},
              },
            },
          )
        : (status: 401, body: apiError('invalid_refresh_token')));
    await tester.pumpWidget(harness(adapter, '/register'));
    await tester.pumpAndSettle();
    await fillRegister(tester);
    await tester.tap(find.byType(Checkbox));
    await tester.tap(find.widgetWithText(FilledButton, 'Зарегистрироваться'));
    await tester.pumpAndSettle();

    expect(find.text('Этот email уже зарегистрирован.'), findsOneWidget);
    expect(body(adapter, '/auth/register')['accept_offer'], isTrue);
  });

  testWidgets('successful registration signs in and returns home', (tester) async {
    final adapter = FakeAdapter((o) => o.path == '/auth/register'
        ? (
            status: 201,
            body: ok({
              'access_token': 'A',
              'refresh_token': 'R',
              'user': {'id': 7, 'name': 'Айгерим', 'email': 'aigerim@example.kz'},
              'registration_bonus_points': 500,
            }),
          )
        : (status: 401, body: apiError('invalid_refresh_token')));
    await tester.pumpWidget(harness(adapter, '/register'));
    await tester.pumpAndSettle();
    await fillRegister(tester);
    await tester.tap(find.byType(Checkbox));
    await tester.tap(find.widgetWithText(FilledButton, 'Зарегистрироваться'));
    await tester.pumpAndSettle();

    expect(find.text('HOME'), findsOneWidget);
    final sent = body(adapter, '/auth/register');
    expect(sent['email'], 'aigerim@example.kz');
    expect(jsonEncode(sent), isNot(contains('phone')), reason: 'empty optional phone is not sent');
  });

  testWidgets('forgot password shows the server confirmation', (tester) async {
    final adapter = FakeAdapter((o) => o.path == '/auth/forgot-password'
        ? (status: 200, body: ok({'sent': true, 'message': 'Если такой email зарегистрирован, мы отправили ссылку.'}))
        : (status: 401, body: apiError('invalid_refresh_token')));
    await tester.pumpWidget(harness(adapter, '/forgot-password'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), 'user@example.kz');
    await tester.tap(find.widgetWithText(FilledButton, 'Отправить ссылку'));
    await tester.pumpAndSettle();

    expect(find.text('Если такой email зарегистрирован, мы отправили ссылку.'), findsOneWidget);
  });

  testWidgets('Sign in with Apple button is shown on iOS only', (tester) async {
    final adapter = FakeAdapter((o) => (status: 401, body: apiError('invalid_refresh_token')));

    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    await tester.pumpWidget(harness(adapter, '/login'));
    await tester.pumpAndSettle();
    expect(find.byType(SignInWithAppleButton), findsNothing);
    expect(find.text('Забыли пароль?'), findsOneWidget);
    expect(find.text('Зарегистрироваться'), findsOneWidget);

    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    await tester.pumpWidget(harness(adapter, '/login'));
    await tester.pumpAndSettle();
    expect(find.byType(SignInWithAppleButton), findsOneWidget);
    debugDefaultTargetPlatformOverride = null;
  });
}
