import 'package:flutter/material.dart';

import '../account_models.dart';
import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../models.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/product_tile.dart';

/// Публичный профиль продавца: объявления, отзывы, подписка.
class SellerScreen extends StatefulWidget {
  const SellerScreen({super.key, required this.id});

  final int id;

  @override
  State<SellerScreen> createState() => _SellerScreenState();
}

class _SellerScreenState extends State<SellerScreen> {
  SellerProfile? _p;
  String? _error;
  bool _busy = false;
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final p = await SessionScope.read(context).api.seller(widget.id);
      if (mounted) setState(() => _p = p);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _follow() async {
    final p = _p!;
    setState(() => _busy = true);
    try {
      final (following, count) = await SessionScope.read(context).api.toggleFollow(p.id);
      if (mounted) {
        setState(() {
          p.isFollowing = following;
          p.followers = count;
        });
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _toggleFavorite(ProductCard item) async {
    await requireLogin(context, () async {
      try {
        final fav = await SessionScope.read(context).api.toggleFavorite(item.id);
        if (mounted) setState(() => item.favorited = fav);
      } on ApiException catch (e) {
        if (mounted) showSnack(context, e.message);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    if (p == null) {
      return Scaffold(
        appBar: AppBar(),
        body: _error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()),
      );
    }
    return Scaffold(
      appBar: AppBar(title: Text(p.name)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(child: _header(p)),
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 4, 12, 4),
                child: SegmentedButton<int>(
                  segments: [
                    ButtonSegment(value: 0, label: Text('Объявления · ${p.items.length}')),
                    ButtonSegment(value: 1, label: Text('Отзывы · ${p.ratingCount}')),
                  ],
                  selected: {_tab},
                  showSelectedIcon: false,
                  onSelectionChanged: (s) => setState(() => _tab = s.first),
                ),
              ),
            ),
            if (_tab == 0)
              p.items.isEmpty
                  ? const SliverToBoxAdapter(
                      child: Padding(
                        padding: EdgeInsets.all(40),
                        child: Text('Активных объявлений нет', textAlign: TextAlign.center, style: TextStyle(color: Colors.black45)),
                      ),
                    )
                  : SliverPadding(
                      padding: const EdgeInsets.all(12),
                      sliver: SliverGrid.builder(
                        gridDelegate: productGridDelegate(context),
                        itemCount: p.items.length,
                        itemBuilder: (_, i) => ProductTile(
                          item: p.items[i],
                          onTap: () => openProduct(context, p.items[i].id),
                          onFavorite: p.isMe ? null : () => _toggleFavorite(p.items[i]),
                        ),
                      ),
                    )
            else
              p.reviews.isEmpty
                  ? const SliverToBoxAdapter(
                      child: Padding(
                        padding: EdgeInsets.all(40),
                        child: Text('Отзывов пока нет', textAlign: TextAlign.center, style: TextStyle(color: Colors.black45)),
                      ),
                    )
                  : SliverPadding(
                      padding: const EdgeInsets.only(top: 12, bottom: 24),
                      sliver: SliverList.builder(
                        itemCount: p.reviews.length,
                        itemBuilder: (_, i) => _review(p.reviews[i]),
                      ),
                    ),
          ],
        ),
      ),
    );
  }

  Widget _header(SellerProfile p) {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
      margin: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 34,
                backgroundColor: const Color(0xFFDBEAFE),
                foregroundImage: p.avatar != null ? NetworkImage(p.avatar!) : null,
                child: Text(p.initial, style: const TextStyle(color: kBrand, fontSize: 26, fontWeight: FontWeight.w700)),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(p.name,
                              style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis),
                        ),
                        if (p.isBusiness) ...[
                          const SizedBox(width: 4),
                          const Icon(Icons.verified_rounded, size: 18, color: kBrand),
                        ],
                      ],
                    ),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        const Icon(Icons.star_rounded, size: 18, color: Color(0xFFF59E0B)),
                        Text(p.ratingCount > 0 ? ' ${p.rating.toStringAsFixed(1)} (${p.ratingCount})' : ' нет оценок',
                            style: const TextStyle(color: Colors.black54)),
                      ],
                    ),
                    if (p.city != null || p.memberSince != null)
                      Text(
                        [if (p.city != null) p.city!, if (p.memberSince != null) 'с ${formatDate(p.memberSince)}'].join(' · '),
                        style: const TextStyle(color: Colors.black45, fontSize: 12.5),
                      ),
                  ],
                ),
              ),
            ],
          ),
          if (p.bio != null) ...[
            const SizedBox(height: 12),
            Text(p.bio!),
          ],
          const SizedBox(height: 14),
          Row(
            children: [
              _stat('${p.sales}', 'продаж'),
              _stat('${p.followers}', 'подписчиков'),
              _stat('${p.items.length}', 'объявлений'),
            ],
          ),
          if (!p.isMe) ...[
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: p.isFollowing
                  ? OutlinedButton(
                      onPressed: _busy ? null : () => requireLogin(context, _follow),
                      child: const Text('Вы подписаны'),
                    )
                  : FilledButton.icon(
                      onPressed: _busy ? null : () => requireLogin(context, _follow),
                      icon: const Icon(Icons.person_add_alt_rounded, size: 18),
                      label: const Text('Подписаться'),
                    ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _stat(String value, String label) => Expanded(
        child: Column(
          children: [
            Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            Text(label, style: const TextStyle(color: Colors.black45, fontSize: 12.5)),
          ],
        ),
      );

  Widget _review(SellerReview r) => SectionCard(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 16,
                  backgroundColor: const Color(0xFFF1F5F9),
                  foregroundImage: r.authorAvatar != null ? NetworkImage(r.authorAvatar!) : null,
                  child: Text(r.author.isEmpty ? '?' : String.fromCharCode(r.author.runes.first),
                      style: const TextStyle(fontSize: 13)),
                ),
                const SizedBox(width: 10),
                Expanded(child: Text(r.author, style: const TextStyle(fontWeight: FontWeight.w600))),
                for (var i = 1; i <= 5; i++)
                  Icon(Icons.star_rounded, size: 16, color: i <= r.rating ? const Color(0xFFF59E0B) : Colors.black12),
              ],
            ),
            if (r.comment.isNotEmpty) ...[const SizedBox(height: 8), Text(r.comment)],
            const SizedBox(height: 6),
            Text(
              [if (r.productTitle != null) r.productTitle!, formatDate(r.createdAt)].join(' · '),
              style: const TextStyle(color: Colors.black45, fontSize: 12),
            ),
          ],
        ),
      );
}
