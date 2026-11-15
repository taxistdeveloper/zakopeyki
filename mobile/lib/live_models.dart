// Прямые эфиры (контракт /api/mobile/v1/streams).

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;

class LiveHost {
  final int id;
  final String name;
  final String? avatar;
  LiveHost(this.id, this.name, this.avatar);

  factory LiveHost.fromJson(Object? j) {
    final m = (j as Map?)?.cast<String, dynamic>() ?? const {};
    return LiveHost(_int(m['id']), _str(m['name']), m['avatar_url'] as String?);
  }
}

class LiveSummary {
  final int id;
  final String title;
  final String? cover;
  final LiveHost host;
  final int viewers;
  final int likes;
  final bool isDemo;
  final bool isMine;

  LiveSummary(this.id, this.title, this.cover, this.host, this.viewers, this.likes, this.isDemo, this.isMine);

  factory LiveSummary.fromJson(Map<String, dynamic> j) => LiveSummary(
        _int(j['id']),
        _str(j['title']),
        j['cover_url'] as String?,
        LiveHost.fromJson(j['host']),
        _int(j['viewers']),
        _int(j['likes']),
        j['is_demo'] == true,
        j['is_mine'] == true,
      );
}

class LiveProduct {
  final int id;
  final String title;
  final String priceText;
  final String? oldPriceText;
  final String? image;
  LiveProduct(this.id, this.title, this.priceText, this.oldPriceText, this.image);

  static LiveProduct? fromJson(Object? j) {
    if (j is! Map) return null;
    return LiveProduct(_int(j['id']), _str(j['title']), _str(j['price_text']), j['old_price_text'] as String?,
        j['image_url'] as String?);
  }
}

class LiveGiveaway {
  final String title;
  final int goal;
  final int participants;
  final String? winner;
  LiveGiveaway(this.title, this.goal, this.participants, this.winner);
}

class LiveState {
  final int id;
  final String title;
  final String? cover;
  final LiveHost host;
  final bool isHost;
  final bool isDemo;
  final int viewers;
  final int likes;
  final String? startedAt;
  final bool chatEnabled;
  final int followers;
  final bool isFollowing;
  final LiveProduct? featured;
  final List<LiveProduct> products;
  final LiveGiveaway? giveaway;
  final List<Map<String, dynamic>> iceServers;

  LiveState({
    required this.id,
    required this.title,
    required this.cover,
    required this.host,
    required this.isHost,
    required this.isDemo,
    required this.viewers,
    required this.likes,
    required this.startedAt,
    required this.chatEnabled,
    required this.followers,
    required this.isFollowing,
    required this.featured,
    required this.products,
    required this.giveaway,
    required this.iceServers,
  });

  factory LiveState.fromJson(Map<String, dynamic> j) {
    final g = j['giveaway'];
    return LiveState(
      id: _int(j['id']),
      title: _str(j['title']),
      cover: j['cover_url'] as String?,
      host: LiveHost.fromJson(j['host']),
      isHost: j['is_host'] == true,
      isDemo: j['is_demo'] == true,
      viewers: _int(j['viewers']),
      likes: _int(j['likes']),
      startedAt: j['started_at'] as String?,
      chatEnabled: j['chat_enabled'] != false,
      followers: _int(j['followers_count']),
      isFollowing: j['is_following'] == true,
      featured: LiveProduct.fromJson(j['featured']),
      products: ((j['products'] as List?) ?? []).map(LiveProduct.fromJson).whereType<LiveProduct>().toList(),
      giveaway: g is Map
          ? LiveGiveaway(_str(g['title']), _int(g['goal']), _int(g['participants']), g['winner_name'] as String?)
          : null,
      iceServers: ((j['ice_servers'] as List?) ?? [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList(),
    );
  }
}

class LiveComment {
  final int id;
  final String user;
  final String body;
  final bool isHost;
  LiveComment(this.id, this.user, this.body, this.isHost);

  factory LiveComment.fromJson(Map<String, dynamic> j) =>
      LiveComment(_int(j['id']), _str(j['user_name']), _str(j['body']), j['is_host'] == true);
}

class LiveSignal {
  final int id;
  final String peerId;
  final String type;
  final Map<String, dynamic>? payload;
  LiveSignal(this.id, this.peerId, this.type, this.payload);

  factory LiveSignal.fromJson(Map<String, dynamic> j) => LiveSignal(
        _int(j['id']),
        _str(j['peer_id']),
        _str(j['type']),
        (j['payload'] as Map?)?.cast<String, dynamic>(),
      );
}

class LiveSetupOptions {
  final List<LiveProduct> products;
  final List<int> durations;
  final bool giveawayEnabled;
  LiveSetupOptions(this.products, this.durations, this.giveawayEnabled);
}
