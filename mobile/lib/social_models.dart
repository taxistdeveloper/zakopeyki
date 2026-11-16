// Личный кабинет (отзывы, подписки, рефералы, бонусы) и истории.

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;

String initialOf(String name) =>
    name.trim().isEmpty ? '?' : String.fromCharCode(name.trim().runes.first).toUpperCase();

class Person {
  final int id;
  final String name;
  final String? login;
  final String? avatar;
  final String? bio;
  final String? since;
  Person(this.id, this.name, this.login, this.avatar, this.bio, this.since);

  factory Person.fromJson(Map<String, dynamic> j) => Person(
        _int(j['id']),
        _str(j['name']),
        j['login'] as String?,
        j['avatar_url'] as String?,
        j['bio'] as String?,
        j['since'] as String?,
      );
}

class ReviewItem {
  final int rating;
  final String comment;
  final String author;
  final String? authorAvatar;
  final String? productTitle;
  final String? createdAt;
  ReviewItem(this.rating, this.comment, this.author, this.authorAvatar, this.productTitle, this.createdAt);

  factory ReviewItem.fromJson(Map<String, dynamic> j) => ReviewItem(
        _int(j['rating']),
        _str(j['comment']),
        _str(j['author_name']),
        j['author_avatar_url'] as String?,
        j['product_title'] as String?,
        j['created_at'] as String?,
      );
}

class ReferralInfo {
  final String code;
  final String url;
  final int bonusPerFriend;
  final int count;
  final List<Person> friends;
  ReferralInfo(this.code, this.url, this.bonusPerFriend, this.count, this.friends);

  factory ReferralInfo.fromJson(Map<String, dynamic> j) => ReferralInfo(
        _str(j['code']),
        _str(j['url']),
        _int(j['bonus_per_friend']),
        _int(j['count']),
        ((j['friends'] as List?) ?? []).map((e) => Person.fromJson(e as Map<String, dynamic>)).toList(),
      );
}

class BonusTx {
  final String label;
  final int amount;
  final int balanceAfter;
  final String? createdAt;
  BonusTx(this.label, this.amount, this.balanceAfter, this.createdAt);
}

class BonusRule {
  final String label;
  final int amount;
  BonusRule(this.label, this.amount);
}

class BonusInfo {
  final int balance;
  final List<BonusRule> rules;
  final List<BonusTx> transactions;
  BonusInfo(this.balance, this.rules, this.transactions);

  factory BonusInfo.fromJson(Map<String, dynamic> j) => BonusInfo(
        _int(j['balance']),
        ((j['rules'] as List?) ?? []).map((e) => BonusRule(_str(e['label']), _int(e['amount']))).toList(),
        ((j['transactions'] as List?) ?? [])
            .map((e) => BonusTx(_str(e['label'], _str(e['type'])), _int(e['amount']), _int(e['balance_after']),
                e['created_at'] as String?))
            .toList(),
      );
}

// ---------------------------------------------------------------- истории

class StoryProduct {
  final int id;
  final String title;
  final String priceText;
  final String? image;
  StoryProduct(this.id, this.title, this.priceText, this.image);
}

class StoryItem {
  final int id;
  final String? mediaUrl;
  final bool isVideo;
  final String? caption;
  final String bgColor;
  final bool commentsEnabled;
  final String? createdAt;
  final StoryProduct? product;

  StoryItem(this.id, this.mediaUrl, this.isVideo, this.caption, this.bgColor, this.commentsEnabled, this.createdAt,
      this.product);

  factory StoryItem.fromJson(Map<String, dynamic> j) {
    final p = j['product'];
    return StoryItem(
      _int(j['id']),
      j['media_url'] as String?,
      j['is_video'] == true,
      j['caption'] as String?,
      _str(j['bg_color'], '#7c3aed'),
      j['comments_enabled'] != false,
      j['created_at'] as String?,
      p is Map
          ? StoryProduct(_int(p['id']), _str(p['title']), _str(p['price_text']), p['image_url'] as String?)
          : null,
    );
  }
}

class StoryGroup {
  final int userId;
  final String userName;
  final String? avatar;
  final bool isMine;
  final List<StoryItem> stories;
  StoryGroup(this.userId, this.userName, this.avatar, this.isMine, this.stories);

  factory StoryGroup.fromJson(Map<String, dynamic> j) {
    final u = (j['user'] as Map).cast<String, dynamic>();
    return StoryGroup(
      _int(u['id']),
      _str(u['name']),
      u['avatar_url'] as String?,
      j['is_mine'] == true,
      ((j['stories'] as List?) ?? []).map((e) => StoryItem.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }
}
