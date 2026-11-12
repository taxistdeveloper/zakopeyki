import 'package:flutter_test/flutter_test.dart';
import 'package:zakopeyki/features/catalog/models.dart';

void main() {
  final cardJson = <String, dynamic>{
    'id': 120,
    'type': 'new',
    'type_label': 'Новый товар',
    'title': 'Samsung Galaxy A57',
    'price': {'amount': 249690, 'currency': 'KZT'},
    'price_text': '249 690 ₸',
    'cover_url': 'https://zakopeyki.kz/public/uploads/products/a.jpg',
    'category_label': 'Электроника',
    'location': null,
    'seller': {'id': 5, 'name': 'ТОО Сети', 'is_business': true},
    'is_favorite': true,
  };

  test('ProductCard parses API card', () {
    final c = ProductCard.fromJson(cardJson);
    expect(c.id, 120);
    expect(c.price?.amount, 249690);
    expect(c.seller.isBusiness, isTrue);
    expect(c.isFavorite, isTrue);
    expect(c.copyWith(isFavorite: false).isFavorite, isFalse);
  });

  test('free item has no money', () {
    final c = ProductCard.fromJson({...cardJson, 'type': 'free', 'price': null, 'price_text': 'Даром'});
    expect(c.price, isNull);
  });

  test('ProductDetail parses detail with seller rating and auction', () {
    final d = ProductDetail.fromJson({
      ...cardJson,
      'type': 'auction',
      'description': 'desc',
      'images': ['https://x/1.jpg', 'https://x/2.jpg'],
      'characteristics': [
        {'name': 'Цвет', 'value': 'Чёрный'},
      ],
      'can_buy': false,
      'web_url': 'https://zakopeyki.kz/product/120',
      'view_count': 7,
      'auction': {'current_bid': 1000, 'bid_step': 100, 'ends_at': '2026-11-20T12:00:00+05:00'},
      'seller': {
        'id': 5,
        'name': 'ТОО Сети',
        'is_business': true,
        'rating': {'average': 4.5, 'count': 12},
      },
    });
    expect(d.images, hasLength(2));
    expect(d.characteristics.single.value, 'Чёрный');
    expect(d.card.seller.ratingCount, 12);
    expect(d.auction?.endsAt?.toUtc().hour, 7);
    expect(d.withFavorite(false).card.isFavorite, isFalse);
  });

  test('RemoteConfig parses fees and legal links', () {
    final c = RemoteConfig.fromJson({
      'site_open': false,
      'listing_types': [
        {'code': 'new', 'label': 'Новый товар'},
      ],
      'fees': {'safe_deal_buyer_percent': 3},
      'legal': {'offer_url': 'https://zakopeyki.kz/offer', 'privacy_url': 'https://zakopeyki.kz/privacy'},
    });
    expect(c.siteOpen, isFalse);
    expect(c.safeDealPercent, 3);
    expect(c.listingTypes.single.code, 'new');
  });
}
