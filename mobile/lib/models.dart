class AppUser {
  final int id;
  final String name;
  final String email;
  final String? phone;
  final String role;
  final String accountType;
  final String businessStatus;
  final String? avatar;
  final String avatarLetter;

  AppUser({
    required this.id,
    required this.name,
    required this.email,
    this.phone,
    required this.role,
    required this.accountType,
    required this.businessStatus,
    this.avatar,
    required this.avatarLetter,
  });

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: _int(j['id']),
        name: _str(j['name']),
        email: _str(j['email']),
        phone: j['phone']?.toString(),
        role: _str(j['role'], 'user'),
        accountType: _str(j['account_type'], 'personal'),
        businessStatus: _str(j['business_status'], 'none'),
        avatar: j['avatar'] as String?,
        avatarLetter: _str(j['avatar_letter'], 'U'),
      );
}

class Category {
  final String name;
  final String label;
  final List<Category> children;

  Category({required this.name, required this.label, this.children = const []});

  factory Category.fromJson(Map<String, dynamic> j) => Category(
        name: _str(j['name']),
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
  final bool siteAccess;
  final AppUser? user;
  final List<ListingType> types;
  final List<Category> categories;

  AppConfig({
    required this.stubMode,
    required this.opensAt,
    required this.siteAccess,
    required this.user,
    required this.types,
    required this.categories,
  });

  factory AppConfig.fromJson(Map<String, dynamic> j) => AppConfig(
        stubMode: j['stub_mode'] == true,
        opensAt: _str(j['opens_at']),
        siteAccess: j['site_access'] == true,
        user: j['user'] is Map<String, dynamic>
            ? AppUser.fromJson(j['user'] as Map<String, dynamic>)
            : null,
        types: ((j['types'] as List?) ?? [])
            .map((e) => ListingType(_str(e['key']), _str(e['label'])))
            .toList(),
        categories: ((j['categories'] as List?) ?? [])
            .map((e) => Category.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class Seller {
  final int id;
  final String name;
  final bool isBusiness;
  final String? avatar;
  final String? since;

  Seller({required this.id, required this.name, required this.isBusiness, this.avatar, this.since});

  factory Seller.fromJson(Map<String, dynamic> j) => Seller(
        id: _int(j['id']),
        name: _str(j['name']),
        isBusiness: j['is_business'] == true,
        avatar: j['avatar'] as String?,
        since: j['since']?.toString(),
      );
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
        price: _int(j['price']),
        priceText: _str(j['price_text']),
        category: _str(j['category']),
        location: _str(j['location']),
        status: _str(j['status'], 'active'),
        image: j['image'] as String?,
        url: _str(j['url']),
        seller: Seller.fromJson((j['seller'] as Map?)?.cast<String, dynamic>() ?? {}),
        favorited: j['favorited'] == true,
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
  final int bids;

  AuctionInfo({required this.currentPrice, required this.bidStep, this.endAt, this.buyNow, required this.bids});

  factory AuctionInfo.fromJson(Map<String, dynamic> j) => AuctionInfo(
        currentPrice: _int(j['current_price']),
        bidStep: _int(j['bid_step']),
        endAt: j['end_at']?.toString(),
        buyNow: j['buy_now'] == null ? null : _int(j['buy_now']),
        bids: _int(j['bids']),
      );
}

class ProductDetail {
  final ProductCard card;
  final String description;
  final List<Characteristic> characteristics;
  final List<String> images;
  final int quantity;
  final bool purchasable;
  final bool isOwner;
  final String? whatsapp;
  final int views;
  final AuctionInfo? auction;
  final List<ProductCard> similar;

  ProductDetail({
    required this.card,
    required this.description,
    required this.characteristics,
    required this.images,
    required this.quantity,
    required this.purchasable,
    required this.isOwner,
    required this.whatsapp,
    required this.views,
    required this.auction,
    required this.similar,
  });

  factory ProductDetail.fromJson(Map<String, dynamic> root) {
    final j = root['item'] as Map<String, dynamic>;
    return ProductDetail(
      card: ProductCard.fromJson(j),
      description: _str(j['description']),
      characteristics: ((j['characteristics'] as List?) ?? [])
          .map((e) => Characteristic(_str(e['name']), _str(e['value'])))
          .toList(),
      images: ((j['images'] as List?) ?? []).map((e) => e.toString()).toList(),
      quantity: _int(j['quantity']),
      purchasable: j['purchasable'] == true,
      isOwner: j['is_owner'] == true,
      whatsapp: j['whatsapp'] as String?,
      views: _int(j['views']),
      auction: j['auction'] is Map<String, dynamic>
          ? AuctionInfo.fromJson(j['auction'] as Map<String, dynamic>)
          : null,
      similar: ((root['similar'] as List?) ?? [])
          .map((e) => ProductCard.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}

class ProductPage {
  final List<ProductCard> items;
  final int page;
  final int total;
  final bool hasMore;

  ProductPage({required this.items, required this.page, required this.total, required this.hasMore});

  factory ProductPage.fromJson(Map<String, dynamic> j) => ProductPage(
        items: ((j['items'] as List?) ?? [])
            .map((e) => ProductCard.fromJson(e as Map<String, dynamic>))
            .toList(),
        page: _int(j['page'], 1),
        total: _int(j['total']),
        hasMore: j['has_more'] == true,
      );
}

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;
