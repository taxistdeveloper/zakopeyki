import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../social_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'seller_screen.dart';

Widget _avatar(String? url, String name, {double radius = 22}) => CircleAvatar(
      radius: radius,
      backgroundColor: const Color(0x2E2563EB),
      foregroundImage: url != null ? NetworkImage(url) : null,
      child: Text(initialOf(name),
          style: TextStyle(color: kBrand, fontWeight: FontWeight.w700, fontSize: radius * 0.8)),
    );

/// Загрузка с ошибкой/повтором — общий каркас экранов кабинета.
class _Loader<T> extends StatefulWidget {
  const _Loader({required this.title, required this.load, required this.builder});

  final String title;
  final Future<T> Function(Api api) load;
  final Widget Function(BuildContext context, T data, Future<void> Function() reload) builder;

  @override
  State<_Loader<T>> createState() => _LoaderState<T>();
}

class _LoaderState<T> extends State<_Loader<T>> {
  T? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await widget.load(SessionScope.read(context).api);
      if (mounted) setState(() => _data = d);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: d == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(onRefresh: _load, child: widget.builder(context, d, _load)),
    );
  }
}

// ---------------------------------------------------------------- отзывы обо мне

class MyReviewsScreen extends StatelessWidget {
  const MyReviewsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _Loader<(double, int, List<ReviewItem>)>(
      title: 'Отзывы обо мне',
      load: (api) => api.myReviews(),
      builder: (context, d, _) {
        final (avg, count, items) = d;
        return ListView(
          padding: const EdgeInsets.only(top: 12, bottom: 24),
          children: [
            SectionCard(
              child: Row(
                children: [
                  Text(count > 0 ? avg.toStringAsFixed(1) : '—',
                      style: const TextStyle(fontSize: 40, fontWeight: FontWeight.w800)),
                  const SizedBox(width: 14),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        for (var i = 1; i <= 5; i++)
                          Icon(Icons.star_rounded,
                              color: i <= avg.round() ? const Color(0xFFF59E0B) : context.pal.hairline),
                      ]),
                      Text('Отзывов: $count', style: TextStyle(color: context.pal.muted)),
                    ],
                  ),
                ],
              ),
            ),
            if (items.isEmpty)
              Padding(
                padding: EdgeInsets.all(32),
                child: Text('Отзывы появятся после завершённых сделок',
                    textAlign: TextAlign.center, style: TextStyle(color: context.pal.muted2)),
              ),
            for (final r in items)
              SectionCard(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      _avatar(r.authorAvatar, r.author, radius: 16),
                      const SizedBox(width: 10),
                      Expanded(child: Text(r.author, style: const TextStyle(fontWeight: FontWeight.w600))),
                      for (var i = 1; i <= 5; i++)
                        Icon(Icons.star_rounded, size: 16,
                            color: i <= r.rating ? const Color(0xFFF59E0B) : context.pal.hairline),
                    ]),
                    if (r.comment.isNotEmpty) ...[const SizedBox(height: 8), Text(r.comment)],
                    const SizedBox(height: 6),
                    Text([if (r.productTitle != null) r.productTitle!, formatDate(r.createdAt)].join(' · '),
                        style: TextStyle(color: context.pal.muted2, fontSize: 12)),
                  ],
                ),
              ),
          ],
        );
      },
    );
  }
}

// ---------------------------------------------------------------- подписки

class FollowsScreen extends StatelessWidget {
  const FollowsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: _Loader<(List<Person>, List<Person>)>(
        title: 'Подписки',
        load: (api) => api.follows(),
        builder: (context, d, reload) {
          final (following, followers) = d;
          return Column(
            children: [
              Material(
                color: context.pal.surface,
                child: TabBar(tabs: [
                  Tab(text: 'Мои подписки · ${following.length}'),
                  Tab(text: 'Подписчики · ${followers.length}'),
                ]),
              ),
              Expanded(
                child: TabBarView(children: [
                  _people(context, following, 'Вы ещё ни на кого не подписаны', reload),
                  _people(context, followers, 'Пока нет подписчиков', reload),
                ]),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _people(BuildContext context, List<Person> list, String empty, Future<void> Function() reload) {
    if (list.isEmpty) {
      return ListView(children: [
        const SizedBox(height: 100),
        EmptyView(icon: Icons.people_outline_rounded, title: empty),
      ]);
    }
    return ListView.separated(
      itemCount: list.length,
      separatorBuilder: (_, _) => const Divider(height: 1, indent: 72),
      itemBuilder: (_, i) {
        final p = list[i];
        return ListTile(
          tileColor: context.pal.surface,
          leading: _avatar(p.avatar, p.name),
          title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(p.bio ?? (p.login != null ? '@${p.login}' : ''), maxLines: 1, overflow: TextOverflow.ellipsis),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () async {
            await Navigator.of(context).push(MaterialPageRoute(builder: (_) => SellerScreen(id: p.id)));
            reload();
          },
        );
      },
    );
  }
}

// ---------------------------------------------------------------- приглашения

class ReferralScreen extends StatelessWidget {
  const ReferralScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _Loader<ReferralInfo>(
      title: 'Пригласить друзей',
      load: (api) => api.referral(),
      builder: (context, r, _) => ListView(
        padding: const EdgeInsets.only(top: 12, bottom: 24),
        children: [
          Container(
            margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              gradient: const LinearGradient(colors: [kBrand, kBrandDark]),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.card_giftcard_rounded, color: Colors.white, size: 32),
                const SizedBox(height: 10),
                Text('+${r.bonusPerFriend} бонусов за каждого друга',
                    style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                const Text('Друг регистрируется по вашей ссылке — бонусы начисляются автоматически.',
                    style: TextStyle(color: Colors.white70)),
                const SizedBox(height: 16),
                Row(children: [
                  Expanded(
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: kBrand),
                      onPressed: () => SharePlus.instance.share(ShareParams(
                        text: 'Покупай и продавай на Закопейках с безопасной сделкой: ${r.url}',
                      )),
                      icon: const Icon(Icons.share_rounded),
                      label: const Text('Поделиться'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  IconButton.outlined(
                    style: IconButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white70)),
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: r.url));
                      showSnack(context, 'Ссылка скопирована');
                    },
                    icon: const Icon(Icons.copy_rounded),
                  ),
                ]),
              ],
            ),
          ),
          SectionCard(
            child: Row(children: [
              Text('Ваш код', style: TextStyle(color: context.pal.muted)),
              const Spacer(),
              SelectableText(r.code, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            ]),
          ),
          SectionCard(
            title: 'Приглашённые · ${r.count}',
            child: r.friends.isEmpty
                ? Text('Пока никого — поделитесь ссылкой', style: TextStyle(color: context.pal.muted))
                : Column(children: [
                    for (final f in r.friends)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: _avatar(f.avatar, f.name, radius: 18),
                        title: Text(f.name),
                        subtitle: Text('с ${formatDate(f.since)}'),
                      ),
                  ]),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------- бонусы

class BonusesScreen extends StatelessWidget {
  const BonusesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _Loader<BonusInfo>(
      title: 'Бонусы',
      load: (api) => api.bonuses(),
      builder: (context, b, _) => ListView(
        padding: const EdgeInsets.only(top: 12, bottom: 24),
        children: [
          Container(
            margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              gradient: const LinearGradient(colors: [Color(0xFFF59E0B), Color(0xFFEA580C)]),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Бонусный баланс', style: TextStyle(color: Colors.white70)),
                Text('${b.balance}', style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w800)),
              ],
            ),
          ),
          SectionCard(
            title: 'Как получить',
            child: Column(children: [
              for (final r in b.rules)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    Expanded(child: Text(r.label)),
                    Text('+${r.amount}', style: const TextStyle(fontWeight: FontWeight.w700, color: Color(0xFFEA580C))),
                  ]),
                ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ReferralScreen())),
                icon: const Icon(Icons.person_add_alt_rounded),
                label: const Text('Пригласить друзей'),
              ),
            ]),
          ),
          SectionCard(
            title: 'История',
            child: b.transactions.isEmpty
                ? Text('Пока нет начислений', style: TextStyle(color: context.pal.muted))
                : Column(children: [
                    for (final t in b.transactions)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Row(children: [
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(t.label),
                              Text(formatDate(t.createdAt, withTime: true),
                                  style: TextStyle(color: context.pal.muted2, fontSize: 12)),
                            ]),
                          ),
                          Text('${t.amount >= 0 ? '+' : ''}${t.amount}',
                              style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  color: t.amount >= 0 ? const Color(0xFF16A34A) : context.pal.ink)),
                        ]),
                      ),
                  ]),
          ),
        ],
      ),
    );
  }
}
