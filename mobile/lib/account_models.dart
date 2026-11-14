// Аккаунт, уведомления, кошелёк, поддержка, профиль продавца, аукцион (контракт /api/mobile/v1).

import 'models.dart';

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;

int _money(Object? v) => v is Map ? _int(v['amount']) : _int(v);

// ---------------------------------------------------------------- аккаунт

class AccountProfile {
  final String firstName;
  final String lastName;
  final String login;
  final String phone;
  final String email;
  final bool emailVerified;
  final bool hasPassword;
  final bool twoFactor;

  AccountProfile(this.firstName, this.lastName, this.login, this.phone, this.email, this.emailVerified,
      this.hasPassword, this.twoFactor);

  factory AccountProfile.fromJson(Map<String, dynamic> j) => AccountProfile(
        _str(j['first_name']),
        _str(j['last_name']),
        _str(j['login']),
        _str(j['phone']),
        _str(j['email']),
        j['email_verified'] == true,
        j['has_password'] == true,
        j['two_factor_enabled'] == true,
      );
}

class AppNotification {
  final int id;
  final String message;
  final String? link;
  final bool read;
  final String? createdAt;
  AppNotification(this.id, this.message, this.link, this.read, this.createdAt);

  factory AppNotification.fromJson(Map<String, dynamic> j) => AppNotification(
        _int(j['id']),
        _str(j['message']),
        j['link'] as String?,
        j['is_read'] == true,
        j['created_at'] as String?,
      );
}

// ---------------------------------------------------------------- кошелёк

class WalletTx {
  final int id;
  final String type;
  final String label;
  final int amount;
  final int balanceAfter;
  final int? orderId;
  final String? createdAt;
  WalletTx(this.id, this.type, this.label, this.amount, this.balanceAfter, this.orderId, this.createdAt);

  factory WalletTx.fromJson(Map<String, dynamic> j) => WalletTx(
        _int(j['id']),
        _str(j['type']),
        _str(j['label'], _str(j['type'])),
        _int(j['amount']),
        _int(j['balance_after']),
        j['order_id'] == null ? null : _int(j['order_id']),
        j['created_at'] as String?,
      );
}

class WalletData {
  final int balance;
  final int held;
  final bool canDeposit;
  final bool canWithdraw;
  final int minDeposit;
  final List<WalletTx> transactions;
  WalletData(this.balance, this.held, this.canDeposit, this.canWithdraw, this.minDeposit, this.transactions);

  factory WalletData.fromJson(Map<String, dynamic> j) => WalletData(
        _money(j['balance']),
        _money(j['held']),
        j['can_deposit'] == true,
        j['can_withdraw'] == true,
        _int(j['min_deposit'], 100),
        ((j['transactions'] as List?) ?? []).map((e) => WalletTx.fromJson(e as Map<String, dynamic>)).toList(),
      );
}

// ---------------------------------------------------------------- поддержка

class SupportCategory {
  final String code;
  final String label;
  SupportCategory(this.code, this.label);
}

class SupportTicket {
  final int id;
  final String number;
  final String subject;
  final String categoryLabel;
  final String status;
  final String statusLabel;
  final String? lastPreview;
  final String? lastMessageAt;
  final int unread;

  SupportTicket(this.id, this.number, this.subject, this.categoryLabel, this.status, this.statusLabel,
      this.lastPreview, this.lastMessageAt, this.unread);

  factory SupportTicket.fromJson(Map<String, dynamic> j) => SupportTicket(
        _int(j['id']),
        _str(j['number']),
        _str(j['subject']),
        _str(j['category_label']),
        _str(j['status'], 'open'),
        _str(j['status_label']),
        j['last_preview'] as String?,
        j['last_message_at'] as String?,
        _int(j['unread']),
      );
}

class SupportMessage {
  final int id;
  final String from; // user | admin | system
  final String body;
  final String? createdAt;
  SupportMessage(this.id, this.from, this.body, this.createdAt);

  factory SupportMessage.fromJson(Map<String, dynamic> j) =>
      SupportMessage(_int(j['id']), _str(j['from'], 'user'), _str(j['body']), j['created_at'] as String?);
}

// ---------------------------------------------------------------- продавец

class SellerReview {
  final int rating;
  final String comment;
  final String author;
  final String? authorAvatar;
  final String? productTitle;
  final String? createdAt;
  SellerReview(this.rating, this.comment, this.author, this.authorAvatar, this.productTitle, this.createdAt);
}

class SellerProfile {
  final int id;
  final String name;
  final String? avatar;
  final bool isBusiness;
  final String? bio;
  final String? city;
  final String? memberSince;
  final double rating;
  final int ratingCount;
  int followers;
  final int sales;
  final bool isMe;
  bool isFollowing;
  final List<ProductCard> items;
  final List<SellerReview> reviews;

  SellerProfile({
    required this.id,
    required this.name,
    required this.avatar,
    required this.isBusiness,
    required this.bio,
    required this.city,
    required this.memberSince,
    required this.rating,
    required this.ratingCount,
    required this.followers,
    required this.sales,
    required this.isMe,
    required this.isFollowing,
    required this.items,
    required this.reviews,
  });

  String get initial => name.trim().isEmpty ? '?' : String.fromCharCode(name.trim().runes.first).toUpperCase();

  factory SellerProfile.fromJson(Map<String, dynamic> j) {
    final r = (j['rating'] as Map?)?.cast<String, dynamic>() ?? const {};
    return SellerProfile(
      id: _int(j['id']),
      name: _str(j['name']),
      avatar: j['avatar_url'] as String?,
      isBusiness: j['is_business'] == true,
      bio: j['bio'] as String?,
      city: j['city'] as String?,
      memberSince: j['member_since'] as String?,
      rating: (r['average'] as num?)?.toDouble() ?? 0,
      ratingCount: _int(r['count']),
      followers: _int(j['followers_count']),
      sales: _int(j['sales_count']),
      isMe: j['is_me'] == true,
      isFollowing: j['is_following'] == true,
      items: ((j['items'] as List?) ?? []).map((e) => ProductCard.fromJson(e as Map<String, dynamic>)).toList(),
      reviews: ((j['reviews'] as List?) ?? [])
          .map((e) => SellerReview(
                _int(e['rating']),
                _str(e['comment']),
                _str(e['author_name']),
                e['author_avatar_url'] as String?,
                e['product_title'] as String?,
                e['created_at'] as String?,
              ))
          .toList(),
    );
  }
}

// ---------------------------------------------------------------- аукцион

class AuctionBid {
  final String user;
  final int amount;
  final String? createdAt;
  AuctionBid(this.user, this.amount, this.createdAt);
}

class AuctionLive {
  final int id;
  final String status;
  final String kind;
  final int currentPrice;
  final int bidStep;
  final int minBid;
  final int? buyNow;
  final String? endsAt;
  final bool isWinner;
  final List<AuctionBid> bids;

  AuctionLive(this.id, this.status, this.kind, this.currentPrice, this.bidStep, this.minBid, this.buyNow, this.endsAt,
      this.isWinner, this.bids);

  bool get active => status == 'active';

  factory AuctionLive.fromJson(Map<String, dynamic> j) => AuctionLive(
        _int(j['id']),
        _str(j['status']),
        _str(j['kind'], 'english'),
        _int(j['current_price']),
        _int(j['bid_step']),
        _int(j['min_bid']),
        j['buy_now_price'] == null ? null : _int(j['buy_now_price']),
        j['ends_at'] as String?,
        j['is_winner'] == true,
        ((j['bids'] as List?) ?? [])
            .map((b) => AuctionBid(_str(b['user']), _int(b['amount']), b['created_at'] as String?))
            .toList(),
      );
}
