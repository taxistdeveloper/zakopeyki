import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:zakopeyki/app.dart' show scaffoldMessengerKey;
import 'package:zakopeyki/core/api/api_client.dart';
import 'package:zakopeyki/core/storage/secure_store.dart';
import 'package:zakopeyki/features/listings/listing_form_screen.dart';
import 'package:zakopeyki/l10n/app_localizations.dart';

import 'support/fakes.dart';

class FakePicker extends ImagePicker {
  FakePicker(this.paths);
  final List<String> paths;

  @override
  Future<List<XFile>> pickMultiImage({
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    int? limit,
    bool requestFullMetadata = true,
  }) async =>
      paths.map(XFile.new).toList();
}

final formJson = {
  'rules_accepted': false,
  'tax_id_kind': 'iin',
  'tax_id_on_file': false,
  'max_photos': 3,
  'can_publish': true,
  'default_location': 'Караганда',
  'types': [
    {'code': 'new', 'label': 'Новый товар', 'has_price': true, 'has_category': true, 'has_quantity': true, 'requires_exchange_for': false},
    {'code': 'used', 'label': 'Товар Б/У', 'has_price': true, 'has_category': true, 'has_quantity': true, 'requires_exchange_for': false},
    {'code': 'free', 'label': 'Даром', 'has_price': false, 'has_category': false, 'has_quantity': false, 'requires_exchange_for': false},
  ],
};

Widget harness(FakeAdapter adapter, ImagePicker picker) {
  final store = MemorySecureStore();
  final router = GoRouter(initialLocation: '/listing/new', routes: [
    GoRoute(path: '/', builder: (_, _) => const Scaffold(body: Text('HOME'))),
    GoRoute(path: '/my-listings', builder: (_, _) => const Scaffold(body: Text('MY LISTINGS'))),
    GoRoute(path: '/listing/new', builder: (_, _) => const ListingFormScreen()),
  ]);
  return ProviderScope(
    overrides: [
      secureStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWithValue(ApiClient(store: store, dio: fakeDio(adapter))),
      imagePickerProvider.overrideWithValue(picker),
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

Future<void> scrollTo(WidgetTester tester, Finder f) =>
    tester.scrollUntilVisible(f, 200, scrollable: find.byType(Scrollable).first);

Finder field(String label) => find.widgetWithText(TextFormField, label);

void main() {
  late Directory tmp;
  late List<String> photos;

  setUp(() {
    tmp = Directory.systemTemp.createTempSync('zk_listing_test');
    photos = [for (var i = 0; i < 2; i++) (File('${tmp.path}/p$i.jpg')..writeAsBytesSync(List.filled(64, i))).path];
  });
  tearDown(() => tmp.deleteSync(recursive: true));

  FakeAdapter adapterWith(({int status, Map<String, dynamic> body}) Function(RequestOptions) onCreate) => FakeAdapter((o) => switch (o.path) {
        '/listings/form' => (status: 200, body: ok(formJson)),
        '/categories' => (status: 200, body: ok({'categories': []})),
        '/listings' => onCreate(o),
        _ => (status: 401, body: apiError('invalid_refresh_token')),
      });

  Future<void> fillAndSubmit(WidgetTester tester) async {
    await tester.tap(find.text('Из галереи'));
    await tester.pumpAndSettle();
    await tester.enterText(field('Название'), 'Велосипед горный');
    await tester.enterText(field('Описание'), 'Почти новый');
    await scrollTo(tester, field('Цена, ₸'));
    await tester.enterText(field('Цена, ₸'), '85000');
    await scrollTo(tester, field('ИИН (12 цифр)'));
    await tester.enterText(field('ИИН (12 цифр)'), '900101312345');
    final rules = find.text('Я принимаю правила размещения объявлений');
    await scrollTo(tester, rules);
    await tester.pumpAndSettle();
    await tester.tap(rules);
    await tester.pumpAndSettle();
    final publish = find.widgetWithText(FilledButton, 'Опубликовать');
    await scrollTo(tester, publish);
    await tester.tap(publish);
    await tester.pumpAndSettle();
  }

  testWidgets('publishing sends multipart with fields, IIN, rules and photos', (tester) async {
    FormData? sent;
    final adapter = adapterWith((o) {
      sent = o.data as FormData;
      return (status: 201, body: ok({'product_id': 55, 'status': 'active', 'listing_bonus_points': 100}));
    });
    await tester.pumpWidget(harness(adapter, FakePicker(photos)));
    await tester.pumpAndSettle();
    await fillAndSubmit(tester);

    expect(sent, isNotNull);
    final fields = Map.fromEntries(sent!.fields.where((e) => !e.key.endsWith('[]')));
    expect(fields['type'], 'used');
    expect(fields['title'], 'Велосипед горный');
    expect(fields['price'], '85000');
    expect(fields['iin'], '900101312345');
    expect(fields['listing_rules_ack'], '1');
    expect(fields['location'], 'Караганда', reason: 'default city from form state');
    expect(fields['cover'], '__new__0');
    expect(sent!.files.map((f) => f.key), ['images[]', 'images[]']);
    expect(find.text('MY LISTINGS'), findsOneWidget);
    expect(find.textContaining('Объявление опубликовано'), findsOneWidget);
  });

  testWidgets('server field error is shown under the field', (tester) async {
    final adapter = adapterWith((o) => (
          status: 422,
          body: {
            'success': false,
            'error': {
              'code': 'tax_id_invalid',
              'message': 'Неверный ИИН',
              'fields': {'iin': 'Неверный ИИН'},
            },
          },
        ));
    await tester.pumpWidget(harness(adapter, FakePicker(photos)));
    await tester.pumpAndSettle();
    await fillAndSubmit(tester);

    await scrollTo(tester, find.text('Неверный ИИН'));
    expect(find.text('Неверный ИИН'), findsOneWidget);
  });

  testWidgets('no request without photos', (tester) async {
    final adapter = adapterWith((o) => (status: 201, body: ok({'product_id': 1})));
    await tester.pumpWidget(harness(adapter, FakePicker(const [])));
    await tester.pumpAndSettle();
    await tester.enterText(field('Название'), 'X');
    await tester.enterText(field('Описание'), 'Y');
    final publish = find.widgetWithText(FilledButton, 'Опубликовать');
    await scrollTo(tester, publish);
    await tester.tap(publish);
    await tester.pumpAndSettle();

    expect(adapter.requests.where((r) => r.path == '/listings'), isEmpty);
    await tester.scrollUntilVisible(find.text('Добавьте хотя бы одно фото'), -200, scrollable: find.byType(Scrollable).first);
    expect(find.text('Добавьте хотя бы одно фото'), findsOneWidget);
  });
}
