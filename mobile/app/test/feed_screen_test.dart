import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zakopeyki/core/api/api_client.dart';
import 'package:zakopeyki/core/api/api_exception.dart';
import 'package:zakopeyki/core/storage/secure_store.dart';
import 'package:zakopeyki/features/catalog/catalog_repository.dart';
import 'package:zakopeyki/features/catalog/feed_screen.dart';
import 'package:zakopeyki/features/catalog/models.dart';
import 'package:zakopeyki/l10n/app_localizations.dart';

import 'support/fakes.dart';

class FakeCatalog extends CatalogRepository {
  FakeCatalog({this.error}) : super(ApiClient(store: MemorySecureStore()));
  final ApiException? error;

  @override
  Future<RemoteConfig> config() async => RemoteConfig.fromJson({
        'listing_types': [
          {'code': 'new', 'label': 'Новый товар'},
          {'code': 'free', 'label': 'Даром'},
        ],
      });

  @override
  Future<FeedPage> feed({String? type, String? query, String? cursor, int limit = 20}) async {
    if (error != null) throw error!;
    return FeedPage([
      ProductCard.fromJson({
        'id': 1,
        'type': 'new',
        'type_label': 'Новый товар',
        'title': 'Велосипед горный',
        'price_text': '85 000 ₸',
        'seller': {'id': 2, 'name': 'Айгерим'},
      }),
    ], null);
  }
}

Widget harness(CatalogRepository repo) => ProviderScope(
      overrides: [
        secureStoreProvider.overrideWithValue(MemorySecureStore()),
        catalogRepositoryProvider.overrideWithValue(repo),
      ],
      child: const MaterialApp(
        locale: Locale('ru'),
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: FeedScreen(),
      ),
    );

void main() {
  testWidgets('feed shows type chips and product tiles', (tester) async {
    await tester.pumpWidget(harness(FakeCatalog()));
    await tester.pumpAndSettle();

    expect(find.text('Все'), findsOneWidget);
    expect(find.text('Даром'), findsOneWidget);
    expect(find.text('Велосипед горный'), findsOneWidget);
    expect(find.text('85 000 ₸'), findsOneWidget);
  });

  testWidgets('closed site shows "coming soon" instead of an error', (tester) async {
    await tester.pumpWidget(harness(FakeCatalog(
      error: const ApiException(code: 'site_closed', message: 'closed', statusCode: 503),
    )));
    await tester.pumpAndSettle();

    expect(find.text('Скоро открытие'), findsOneWidget);
  });
}
