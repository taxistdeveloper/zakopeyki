// Модели каталога. Цены и правила приходят с сервера — клиент их только отображает.
library;

class Money {
  const Money(this.amount, this.currency);
  final int amount;
  final String currency;

  static Money? fromJson(Object? j) {
    if (j is! Map<String, dynamic>) return null;
    return Money((j['amount'] as num).toInt(), (j['currency'] ?? 'KZT').toString());
  }
}

class SellerSummary {
  const SellerSummary({
    required this.id,
    required this.name,
    required this.isBusiness,
    this.avatarUrl,
    this.ratingAverage = 0,
    this.ratingCount = 0,
  });

  final int id;
  final String name;
  final bool isBusiness;
  final String? avatarUrl;
  final double ratingAverage;
  final int ratingCount;

  factory SellerSummary.fromJson(Map<String, dynamic> j) {
    final rating = j['rating'] is Map<String, dynamic> ? j['rating'] as Map<String, dynamic> : const {};
    return SellerSummary(
      id: (j['id'] as num?)?.toInt() ?? 0,
      name: (j['name'] ?? '').toString(),
      isBusiness: j['is_business'] == true,
      avatarUrl: j['avatar_url'] as String?,
      ratingAverage: (rating['average'] as num?)?.toDouble() ?? 0,
      ratingCount: (rating['count'] as num?)?.toInt() ?? 0,
    );
  }
}

class ProductCard {
  const ProductCard({
    required this.id,
    required this.type,
    required this.typeLabel,
    required this.title,
    required this.priceText,
    required this.seller,
    this.price,
    this.coverUrl,
    this.categoryLabel,
    this.location,
    this.isFavorite = false,
  });

  final int id;
  final String type;
  final String typeLabel;
  final String title;
  final Money? price;
  final String priceText;
  final String? coverUrl;
  final String? categoryLabel;
  final String? location;
  final SellerSummary seller;
  final bool isFavorite;

  ProductCard copyWith({bool? isFavorite}) => ProductCard(
        id: id,
        type: type,
        typeLabel: typeLabel,
        title: title,
        priceText: priceText,
        seller: seller,
        price: price,
        coverUrl: coverUrl,
        categoryLabel: categoryLabel,
        location: location,
        isFavorite: isFavorite ?? this.isFavorite,
      );

  factory ProductCard.fromJson(Map<String, dynamic> j) => ProductCard(
        id: (j['id'] as num).toInt(),
        type: (j['type'] ?? '').toString(),
        typeLabel: (j['type_label'] ?? '').toString(),
        title: (j['title'] ?? '').toString(),
        price: Money.fromJson(j['price']),
        priceText: (j['price_text'] ?? '').toString(),
        coverUrl: j['cover_url'] as String?,
        categoryLabel: j['category_label'] as String?,
        location: j['location'] as String?,
        seller: SellerSummary.fromJson((j['seller'] as Map<String, dynamic>?) ?? const {}),
        isFavorite: j['is_favorite'] == true,
      );
}

class Characteristic {
  const Characteristic(this.name, this.value);
  final String name;
  final String value;
}

class AuctionInfo {
  const AuctionInfo({required this.currentBid, required this.bidStep, this.endsAt, this.buyNow});
  final int currentBid;
  final int bidStep;
  final DateTime? endsAt;
  final int? buyNow;
}

class ProductDetail {
  const ProductDetail({
    required this.card,
    required this.description,
    required this.images,
    required this.characteristics,
    required this.canBuy,
    required this.webUrl,
    required this.viewCount,
    this.exchangeFor,
    this.quantityAvailable,
    this.auction,
  });

  final ProductCard card;
  final String description;
  final List<String> images;
  final List<Characteristic> characteristics;
  final bool canBuy;
  final String webUrl;
  final int viewCount;
  final String? exchangeFor;
  final int? quantityAvailable;
  final AuctionInfo? auction;

  ProductDetail withFavorite(bool value) => ProductDetail(
        card: card.copyWith(isFavorite: value),
        description: description,
        images: images,
        characteristics: characteristics,
        canBuy: canBuy,
        webUrl: webUrl,
        viewCount: viewCount,
        exchangeFor: exchangeFor,
        quantityAvailable: quantityAvailable,
        auction: auction,
      );

  factory ProductDetail.fromJson(Map<String, dynamic> j) {
    final a = j['auction'];
    return ProductDetail(
      card: ProductCard.fromJson(j),
      description: (j['description'] ?? '').toString(),
      images: ((j['images'] as List?) ?? const []).whereType<String>().toList(),
      characteristics: ((j['characteristics'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map((c) => Characteristic((c['name'] ?? '').toString(), (c['value'] ?? '').toString()))
          .toList(),
      canBuy: j['can_buy'] == true,
      webUrl: (j['web_url'] ?? '').toString(),
      viewCount: (j['view_count'] as num?)?.toInt() ?? 0,
      exchangeFor: j['exchange_for'] as String?,
      quantityAvailable: (j['quantity_available'] as num?)?.toInt(),
      auction: a is Map<String, dynamic>
          ? AuctionInfo(
              currentBid: (a['current_bid'] as num?)?.toInt() ?? 0,
              bidStep: (a['bid_step'] as num?)?.toInt() ?? 0,
              endsAt: DateTime.tryParse((a['ends_at'] ?? '').toString()),
              buyNow: (a['buy_now'] as num?)?.toInt(),
            )
          : null,
    );
  }
}

class ListingType {
  const ListingType(this.code, this.label);
  final String code;
  final String label;
}

class RemoteConfig {
  const RemoteConfig({
    required this.siteOpen,
    required this.listingTypes,
    required this.safeDealPercent,
    required this.offerUrl,
    required this.privacyUrl,
  });

  final bool siteOpen;
  final List<ListingType> listingTypes;
  final int safeDealPercent;
  final String offerUrl;
  final String privacyUrl;

  factory RemoteConfig.fromJson(Map<String, dynamic> j) {
    final fees = (j['fees'] as Map<String, dynamic>?) ?? const {};
    final legal = (j['legal'] as Map<String, dynamic>?) ?? const {};
    return RemoteConfig(
      siteOpen: j['site_open'] != false,
      listingTypes: ((j['listing_types'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map((t) => ListingType(t['code'].toString(), t['label'].toString()))
          .toList(),
      safeDealPercent: (fees['safe_deal_buyer_percent'] as num?)?.toInt() ?? 0,
      offerUrl: (legal['offer_url'] ?? '').toString(),
      privacyUrl: (legal['privacy_url'] ?? '').toString(),
    );
  }
}

class FeedPage {
  const FeedPage(this.items, this.nextCursor);
  final List<ProductCard> items;
  final String? nextCursor;
}
