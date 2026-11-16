import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:zakopeyki/app.dart' show scaffoldMessengerKey;
import 'package:zakopeyki/core/api/api_client.dart';
import 'package:zakopeyki/core/storage/secure_store.dart';
import 'package:zakopeyki/features/commerce/checkout_screen.dart';
import 'package:zakopeyki/features/commerce/commerce_repository.dart';
import 'package:zakopeyki/features/commerce/orders_screen.dart';
import 'package:zakopeyki/l10n/app_localizations.dart';

import 'support/fakes.dart';

Map<String, dynamic> productJson = {
  'id': 120,
  'type': 'new',
  'type_label': 'Новый товар',
  'title': 'Samsung Galaxy A57',
  'price_text': '249 690 ₸',
  'seller': {'id': 5, 'name': 'ТОО Сети'},
};

Map<String, dynamic> optionsJson({int wallet = 0}) => {
      'items': [
        {'product': productJson, 'qty': 1, 'amount': 249690},
      ],
      'subtotal': {'amount': 249690, 'currency': 'KZT'},
      'deal_modes': [
        {'code': 'escrow', 'fee': 7491, 'total': 257181},
        {'code': 'direct', 'fee': 0, 'total': 249690},
      ],
      'delivery_methods': [
        {'code': 'pickup', 'available_in_app': true},
        {'code': 'cdek', 'available_in_app': false},
      ],
      'payment_methods': [
        {'code': 'card', 'available': true, 'simulated': false},
        {'code': 'wallet', 'available': true, 'balance': wallet},
      ],
      'inspection_hours': 24,
    };

Widget harness(FakeAdapter adapter, String initial, {PaymentLauncher? launcher}) {
  final store = MemorySecureStore();
  final router = GoRouter(initialLocation: initial, routes: [
    GoRoute(path: '/profile', builder: (_, _) => const Scaffold(body: Text('PROFILE'))),
    GoRoute(path: '/checkout', builder: (_, _) => const CheckoutScreen(target: CheckoutTarget.product(120))),
    GoRoute(path: '/order/:id', builder: (_, s) => OrderScreen(orderId: int.parse(s.pathParameters['id']!))),
    GoRoute(path: '/orders', builder: (_, _) => const Scaffold(body: Text('ORDERS'))),
  ]);
  return ProviderScope(
    overrides: [
      secureStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWithValue(ApiClient(store: store, dio: fakeDio(adapter))),
      if (launcher != null) paymentLauncherProvider.overrideWithValue(launcher),
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

Map<String, dynamic> orderJson({String status = 'awaiting_payment', String role = 'buyer', List<String> actions = const ['cancel']}) => {
      'id': 61,
      'role': role,
      'status': status,
      'status_label': status == 'escrowed' ? 'Ожидает отправки' : 'Ожидает оплаты',
      'deal_mode': 'escrow',
      'product': {'id': 120, 'title': 'Samsung Galaxy A57', 'image_url': null},
      'quantity': 1,
      'amount': {'amount': 249690, 'currency': 'KZT'},
      'arbitration_fee': {'amount': 7491, 'currency': 'KZT'},
      'total': {'amount': 257181, 'currency': 'KZT'},
      'counterparty': 'ТОО Сети',
      'is_final': false,
      'actions': actions,
      'delivery_method': 'pickup',
      'dispute_reasons': ['not_as_described'],
    };

Future<void> scrollTo(WidgetTester tester, Finder f) async {
  await tester.scrollUntilVisible(f, 200, scrollable: find.byType(Scrollable).first);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('Safe Deal by card: correct request, payment page opened with server URL, then order', (tester) async {
    Map<String, dynamic>? sent;
    String? openedUrl;
    final adapter = FakeAdapter((o) => switch (o.path) {
          '/checkout/options' => (status: 200, body: ok(optionsJson())),
          '/checkout' => () {
              sent = o.data as Map<String, dynamic>;
              return (
                status: 201,
                body: ok({
                  'order_ids': [61],
                  'payment': {'status': 'pending', 'payment_url': 'https://zakopeyki.kz/payments/halyk/app/abc'},
                }),
              );
            }(),
          '/orders/61' => (status: 200, body: ok({'order': orderJson()})),
          _ => (status: 401, body: apiError('invalid_refresh_token')),
        });
    await tester.pumpWidget(harness(adapter, '/checkout', launcher: (url) async {
      openedUrl = url;
      return 'success';
    }));
    await tester.pumpAndSettle();

    expect(find.text('Безопасная сделка'), findsOneWidget);
    expect(find.text('Напрямую продавцу'), findsOneWidget);
    final pay = find.widgetWithText(FilledButton, 'Оплатить 257 181 ₸');
    await scrollTo(tester, pay);
    expect(pay, findsOneWidget, reason: 'Safe Deal total incl. 3% fee from server');
    await tester.tap(pay);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));

    expect(sent, {'product_id': 120, 'qty': 1, 'deal_mode': 'escrow', 'delivery_method': 'pickup', 'payment_method': 'card'});
    expect(openedUrl, 'https://zakopeyki.kz/payments/halyk/app/abc');
    // Заказ ждёт оплаты: на экране индикатор и опрос статуса — pumpAndSettle не подходит
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    expect(find.text('Заказ №61'), findsOneWidget);
    expect(find.text('Ожидает оплаты'), findsOneWidget);
    await tester.pumpWidget(const SizedBox()); // закрыть экран → остановить таймер опроса
  });

  testWidgets('wallet without enough money blocks payment; CDEK not selectable in app', (tester) async {
    final adapter = FakeAdapter((o) => o.path == '/checkout/options'
        ? (status: 200, body: ok(optionsJson(wallet: 1000)))
        : (status: 401, body: apiError('invalid_refresh_token')));
    await tester.pumpWidget(harness(adapter, '/checkout'));
    await tester.pumpAndSettle();

    final cdek = tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'CDEK'));
    expect(cdek.onSelected, isNull);
    await scrollTo(tester, find.text('С кошелька'));
    await tester.tap(find.text('С кошелька'));
    await tester.pumpAndSettle();
    await scrollTo(tester, find.text('Недостаточно средств на кошельке'));
    expect(find.text('Недостаточно средств на кошельке'), findsOneWidget);
    final pay = find.widgetWithText(FilledButton, 'Оплатить 257 181 ₸');
    await scrollTo(tester, pay);
    expect(tester.widget<FilledButton>(pay).onPressed, isNull);
  });

  testWidgets('seller ships with a tracking number', (tester) async {
    Map<String, dynamic>? shipped;
    final adapter = FakeAdapter((o) => switch (o.path) {
          '/orders/61' => (status: 200, body: ok({'order': orderJson(status: 'escrowed', role: 'seller', actions: ['ship'])})),
          '/orders/61/ship' => () {
              shipped = o.data as Map<String, dynamic>;
              return (status: 200, body: ok({'order': orderJson(status: 'shipped', role: 'seller', actions: [])}));
            }(),
          _ => (status: 401, body: apiError('invalid_refresh_token')),
        });
    await tester.pumpWidget(harness(adapter, '/order/61'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Отправил товар'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, 'KZ123456789');
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Подтвердить'));
    await tester.pumpAndSettle();

    expect(shipped, {'tracking': 'KZ123456789', 'carrier': ''});
  });
}
