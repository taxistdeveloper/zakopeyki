// Модели под контракт боевого API /api/mobile/v1 (App\Services\MobileApi\Presenter).

class AppUser {
  final int id;
  final String name;
  final String email;
  final String? phone;
  final String? avatar;
  final bool isBusiness;
  final bool emailVerified;

  AppUser({
    required this.id,
    required this.name,
    required this.email,
    this.phone,
    this.avatar,
    this.isBusiness = false,
    this.emailVerified = false,
  });

  String get avatarLetter {
    final n = name.trim();
    return n.isEmpty ? 'U' : String.fromCharCode(n.runes.first).toUpperCase();
  }

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: _int(j['id']),
        name: _str(j['name']),
        email: _str(j['email']),
        phone: j['phone']?.toString(),
        avatar: j['avatar_url'] as String?,
        isBusiness: j['is_business'] == true,
        emailVerified: j['email_verified'] == true,
      );
}

class Category {
  /// Значение для фильтра ?category= (пустая строка — «все»).
  final String name;
  final String label;
  final List<Category> children;

  Category({required this.name, required this.label, this.children = const []});

  factory Category.fromJson(Map<String, dynamic> j) => Category(
        name: _str(j['value']),
        label: _str(j['label']),
        children: ((j['children'] as List?) ?? [])
            .map((e) => Category.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class ListingType {
  final String key;
  final String label;
  ListingType(this.key, this.label);
}

class AppConfig {
  final bool stubMode;
  final String opensAt;
  final List<ListingType> types;
  List<Category> categories;

  AppConfig({
    required this.stubMode,
    required this.opensAt,
    required this.types,
    this.categories = const [],
  });

  factory AppConfig.fromJson(Map<String, dynamic> j) => AppConfig(
        stubMode: j['site_open'] == false,
        opensAt: _str(j['site_opens_at']),
        types: ((j['listing_types'] as List?) ?? [])
            .map((e) => ListingType(_str(e['code']), _str(e['label'])))
            .toList(),
      );
}

class Seller {
  final int id;
  final String name;
  final bool isBusiness;
  final String? avatar;
  final String? since;
  final double rating;
  final int ratingCount;

  Seller({
    required this.id,
    required this.name,
    required this.isBusiness,
    this.avatar,
    this.since,
    this.rating = 0,
    this.ratingCount = 0,
  });

  factory Seller.fromJson(Map<String, dynamic> j) {
    final r = (j['rating'] as Map?)?.cast<String, dynamic>() ?? const {};
    return Seller(
      id: _int(j['id']),
      name: _str(j['name']),
      isBusiness: j['is_business'] == true,
      avatar: j['avatar_url'] as String?,
      since: j['member_since']?.toString(),
      rating: (r['average'] as num?)?.toDouble() ?? 0,
      ratingCount: _int(r['count']),
    );
  }
}

class ProductCard {
  final int id;
  final String title;
  final String type;
  final String typeLabel;
  final int price;
  final String priceText;
  final String category;
  final String location;
  final String status;
  final String? image;
  final String url;
  final Seller seller;
  bool favorited;

  ProductCard({
    required this.id,
    required this.title,
    required this.type,
    required this.typeLabel,
    required this.price,
    required this.priceText,
    required this.category,
    required this.location,
    required this.status,
    required this.image,
    required this.url,
    required this.seller,
    required this.favorited,
  });

  factory ProductCard.fromJson(Map<String, dynamic> j) => ProductCard(
        id: _int(j['id']),
        title: _str(j['title']),
        type: _str(j['type']),
        typeLabel: _str(j['type_label']),
        price: _int((j['price'] as Map?)?['amount']),
        priceText: _str(j['price_text']),
        category: _str(j['category_label'] ?? j['category']),
        location: _str(j['location']),
        status: _str(j['status'], 'active'),
        image: j['cover_url'] as String?,
        url: _str(j['web_url'], '/product/${j['id']}'),
        seller: Seller.fromJson((j['seller'] as Map?)?.cast<String, dynamic>() ?? {}),
        favorited: j['is_favorite'] == true,
      );
}

class Characteristic {
  final String name;
  final String value;
  Characteristic(this.name, this.value);
}

class AuctionInfo {
  final int currentPrice;
  final int bidStep;
  final String? endAt;
  final int? buyNow;

  AuctionInfo({required this.currentPrice, required this.bidStep, this.endAt, this.buyNow});

  factory AuctionInfo.fromJson(Map<String, dynamic> j) => AuctionInfo(
        currentPrice: _int(j['current_bid']),
        bidStep: _int(j['bid_step']),
        endAt: j['ends_at']?.toString(),
        buyNow: j['buy_now'] == null ? null : _int(j['buy_now']),
      );
}

class ProductDetail {
  final ProductCard card;
  final String description;
  final List<Characteristic> characteristics;
  final List<String> images;
  final int? quantity;
  final bool purchasable;
  final String? exchangeFor;
  final int views;
  final AuctionInfo? auction;

  ProductDetail({
    required this.card,
    required this.description,
    required this.characteristics,
    required this.images,
    required this.quantity,
    required this.purchasable,
    required this.exchangeFor,
    required this.views,
    required this.auction,
  });

  factory ProductDetail.fromJson(Map<String, dynamic> root) {
    final j = root['product'] as Map<String, dynamic>;
    return ProductDetail(
      card: ProductCard.fromJson(j),
      description: _str(j['description']),
      characteristics: ((j['characteristics'] as List?) ?? [])
          .map((e) => Characteristic(_str(e['name']), _str(e['value'])))
          .where((c) => c.name.isNotEmpty)
          .toList(),
      images: ((j['images'] as List?) ?? []).map((e) => e.toString()).toList(),
      quantity: j['quantity_available'] == null ? null : _int(j['quantity_available']),
      purchasable: j['can_buy'] == true,
      exchangeFor: j['exchange_for'] as String?,
      views: _int(j['view_count']),
      auction: j['auction'] is Map<String, dynamic>
          ? AuctionInfo.fromJson(j['auction'] as Map<String, dynamic>)
          : null,
    );
  }
}

class ProductPage {
  final List<ProductCard> items;
  final String? nextCursor;

  ProductPage({required this.items, required this.nextCursor});

  bool get hasMore => nextCursor != null;
}

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;
