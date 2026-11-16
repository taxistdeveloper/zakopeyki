import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:zakopeyki/app.dart' show scaffoldMessengerKey;
import 'package:zakopeyki/core/api/api_client.dart';
import 'package:zakopeyki/core/storage/secure_store.dart';
import 'package:zakopeyki/features/profile/delete_account_screen.dart';
import 'package:zakopeyki/l10n/app_localizations.dart';

import 'support/fakes.dart';

Widget harness(FakeAdapter adapter, MemorySecureStore store) {
  final router = GoRouter(initialLocation: '/delete-account', routes: [
    GoRoute(path: '/', builder: (_, _) => const Scaffold(body: Text('HOME'))),
    GoRoute(path: '/profile', builder: (_, _) => const Scaffold(body: Text('PROFILE'))),
    GoRoute(path: '/login', builder: (_, _) => const Scaffold(body: Text('LOGIN'))),
    GoRoute(path: '/delete-account', builder: (_, _) => const DeleteAccountScreen()),
  ]);
  return ProviderScope(
    overrides: [
      secureStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWithValue(ApiClient(store: store, dio: fakeDio(adapter))),
    ],
    child: MaterialApp.router(
      scaffoldMessengerKey: scaffoldMessengerKey,
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

Finder get deleteButton => find.widgetWithText(FilledButton, 'Удалить аккаунт навсегда');

Future<void> scrollTo(WidgetTester tester, Finder f) =>
    tester.scrollUntilVisible(f, 200, scrollable: find.byType(Scrollable).first);

void main() {
  testWidgets('blockers are shown and deletion is not offered', (tester) async {
    final adapter = FakeAdapter((o) => o.path == '/me/deletion'
        ? (
            status: 200,
            body: ok({
              'can_delete': false,
              'requires_password': true,
              'blockers': [
                {'code': 'open_orders', 'count': 2, 'message': 'Есть незавершённые сделки (2).'},
              ],
            }),
          )
        : (status: 401, body: apiError('invalid_refresh_token')));
    await tester.pumpWidget(harness(adapter, MemorySecureStore()));
    await tester.pumpAndSettle();

    expect(find.textContaining('Есть незавершённые сделки (2).'), findsOneWidget);
    expect(deleteButton, findsNothing);
  });

  testWidgets('password account: button enabled only after password + confirmation, then signs out', (tester) async {
    final store = MemorySecureStore()..refresh = 'R';
    final adapter = FakeAdapter((o) => switch (o.path) {
          '/me/deletion' => (status: 200, body: ok({'can_delete': true, 'requires_password': true, 'blockers': []})),
          '/me/delete' => (status: 200, body: ok({'deleted': true})),
          _ => (status: 401, body: apiError('invalid_refresh_token')),
        });
    await tester.pumpWidget(harness(adapter, store));
    await tester.pumpAndSettle();

    await scrollTo(tester, deleteButton);
    expect(tester.widget<FilledButton>(deleteButton).onPressed, isNull, reason: 'disabled before confirmation');
    await tester.enterText(find.byType(TextField), 'Secret123');
    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    expect(tester.widget<FilledButton>(deleteButton).onPressed, isNotNull);

    await tester.tap(deleteButton);
    await tester.pumpAndSettle();

    final body = adapter.requests.lastWhere((r) => r.path == '/me/delete').data as Map<String, dynamic>;
    expect(body, {'confirm': true, 'password': 'Secret123'});
    expect(find.text('HOME'), findsOneWidget);
    expect(find.text('Аккаунт удалён'), findsOneWidget, reason: 'confirmation survives navigation');
    expect(store.refresh, isNull, reason: 'local session cleared');
  });

  testWidgets('account without password asks to sign in again when server requires re-auth', (tester) async {
    final adapter = FakeAdapter((o) => switch (o.path) {
          '/me/deletion' => (status: 200, body: ok({'can_delete': true, 'requires_password': false, 'blockers': []})),
          '/me/delete' => (status: 403, body: apiError('reauth_required', 'Войдите заново.')),
          _ => (status: 401, body: apiError('invalid_refresh_token')),
        });
    await tester.pumpWidget(harness(adapter, MemorySecureStore()));
    await tester.pumpAndSettle();
    expect(find.byType(TextField), findsNothing, reason: 'no password field for Apple-only accounts');

    await scrollTo(tester, find.byType(Checkbox));
    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    await scrollTo(tester, deleteButton);
    await tester.tap(deleteButton);
    await tester.pumpAndSettle();

    expect(find.text('Войти заново'), findsOneWidget);
    await scrollTo(tester, deleteButton);
    expect(tester.widget<FilledButton>(deleteButton).onPressed, isNull, reason: 'blocked until re-sign-in');
  });
}
