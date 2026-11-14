import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../main.dart';
import '../models.dart';
import '../nav.dart';
import '../widgets/product_tile.dart';

class ProductScreen extends StatefulWidget {
  const ProductScreen({super.key, required this.id});

  final int id;

  @override
  State<ProductScreen> createState() => _ProductScreenState();
}

class _ProductScreenState extends State<ProductScreen> {
  ProductDetail? _data;
  String? _error;
  int _photo = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SessionScope.read(context).api.product(widget.id);
      if (mounted) setState(() => _data = d);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _toggleFavorite() async {
    final d = _data;
    if (d == null) return;
    await requireLogin(context, () async {
      try {
        final fav = await SessionScope.read(context).api.toggleFavorite(d.card.id);
        if (mounted) setState(() => d.card.favorited = fav);
      } on ApiException catch (e) {
        if (mounted) showSnack(context, e.message);
      }
    });
  }

  Future<void> _launch(Uri uri) async {
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) && mounted) {
      showSnack(context, 'Не удалось открыть ссылку');
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        title: Text(d?.card.title ?? ''),
        actions: [
          if (d != null)
            IconButton(
              onPressed: _toggleFavorite,
              icon: Icon(
                d.card.favorited ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                color: d.card.favorited ? Colors.redAccent : null,
              ),
            ),
          if (d != null)
            IconButton(
              tooltip: 'Открыть на сайте',
              onPressed: () => openWeb(context, '/product/${d.card.id}?full=1', title: d.card.title),
              icon: const Icon(Icons.open_in_new_rounded),
            ),
        ],
      ),
      body: d == null ? _placeholder() : _body(d),
      bottomNavigationBar: d == null ? null : _actions(d),
    );
  }

  Widget _placeholder() {
    if (_error == null) return const Center(child: CircularProgressIndicator());
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(_error!),
          const SizedBox(height: 12),
          OutlinedButton(onPressed: _load, child: const Text('Повторить')),
        ],
      ),
    );
  }

  Widget _body(ProductDetail d) {
    final text = Theme.of(context).textTheme;
    final images = d.images.isEmpty ? <String?>[null] : d.images;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          AspectRatio(
            aspectRatio: 1,
            child: Stack(
              children: [
                PageView.builder(
                  itemCount: images.length,
                  onPageChanged: (i) => setState(() => _photo = i),
                  itemBuilder: (_, i) => GestureDetector(
                    onTap: images[i] == null ? null : () => _openGallery(d.images, i),
                    child: ProductImage(url: images[i], type: d.card.type),
                  ),
                ),
                if (images.length > 1)
                  Positioned(
                    bottom: 10,
                    left: 0,
                    right: 0,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        for (var i = 0; i < images.length; i++)
                          Container(
                            width: 8,
                            height: 8,
                            margin: const EdgeInsets.symmetric(horizontal: 3),
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: i == _photo ? Colors.white : Colors.white54,
                            ),
                          ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    TypeBadge(type: d.card.type, label: d.card.typeLabel),
                    if (d.card.status != 'active') ...[
                      const SizedBox(width: 8),
                      Chip(
                        label: Text(_statusLabel(d.card.status)),
                        visualDensity: VisualDensity.compact,
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 10),
                Text(d.card.priceText, style: text.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
                const SizedBox(height: 6),
                Text(d.card.title, style: text.titleLarge),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 14,
                  runSpacing: 4,
                  children: [
                    if (d.card.location.isNotEmpty)
                      _meta(Icons.place_outlined, d.card.location),
                    if (d.card.category.isNotEmpty && d.card.category != 'Разное')
                      _meta(Icons.category_outlined, d.card.category),
                    _meta(Icons.visibility_outlined, '${d.views}'),
                  ],
                ),
                if (d.auction != null) _auctionBox(d.auction!),
                if (d.description.isNotEmpty) ...[
                  const SizedBox(height: 20),
                  Text('Описание', style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 6),
                  SelectableText(d.description, style: text.bodyLarge),
                ],
                if (d.characteristics.isNotEmpty) ...[
                  const SizedBox(height: 20),
                  Text('Характеристики', style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 6),
                  for (final c in d.characteristics)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: Text(c.name, style: const TextStyle(color: Colors.black54))),
                          const SizedBox(width: 12),
                          Expanded(child: Text(c.value)),
                        ],
                      ),
                    ),
                ],
                const SizedBox(height: 20),
                _sellerCard(d.card.seller),
              ],
            ),
          ),
          if (d.similar.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 24, 16, 8),
              child: Text('Похожие объявления',
                  style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
            ),
            SizedBox(
              height: 270,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                itemCount: d.similar.length,
                separatorBuilder: (_, _) => const SizedBox(width: 10),
                itemBuilder: (_, i) => SizedBox(
                  width: 160,
                  child: ProductTile(
                    item: d.similar[i],
                    onTap: () => openProduct(context, d.similar[i].id),
                  ),
                ),
              ),
            ),
          ],
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _meta(IconData icon, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: Colors.black45),
          const SizedBox(width: 4),
          Flexible(child: Text(label, style: const TextStyle(color: Colors.black54))),
        ],
      );

  Widget _auctionBox(AuctionInfo a) {
    return Container(
      margin: const EdgeInsets.only(top: 16),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF2F2),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Текущая ставка: ${_money(a.currentPrice)}',
              style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text('Ставок: ${a.bids}${a.bidStep > 0 ? ' · шаг ${_money(a.bidStep)}' : ''}'),
          if (a.endAt != null) Text('Завершение: ${a.endAt}'),
          if (a.buyNow != null && a.buyNow! > 0) Text('Купить сразу: ${_money(a.buyNow!)}'),
        ],
      ),
    );
  }

  Widget _sellerCard(Seller s) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 22,
            backgroundImage: s.avatar != null ? NetworkImage(s.avatar!) : null,
            child: s.avatar == null ? Text(s.name.isEmpty ? '?' : s.name.characters.first) : null,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(s.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w600)),
                    ),
                    if (s.isBusiness) ...[
                      const SizedBox(width: 4),
                      const Icon(Icons.verified_rounded, size: 16, color: Color(0xFF2563EB)),
                    ],
                  ],
                ),
                if (s.since != null)
                  Text('На сайте с ${s.since!.split(' ').first}',
                      style: const TextStyle(color: Colors.black54, fontSize: 12)),
              ],
            ),
          ),
          TextButton(
            onPressed: () => openWeb(context, '/users/${s.id}', title: s.name),
            child: const Text('Профиль'),
          ),
        ],
      ),
    );
  }

  Widget _actions(ProductDetail d) {
    if (d.isOwner) {
      return _bar([
        Expanded(
          child: FilledButton(
            onPressed: () => openWeb(context, '/profile?tab=lots', title: 'Мои объявления'),
            child: const Text('Управлять объявлением'),
          ),
        ),
      ]);
    }
    final id = d.card.id;
    final buttons = <Widget>[
      if (d.whatsapp != null)
        IconButton.filledTonal(
          onPressed: () => _launch(Uri.parse('https://wa.me/${d.whatsapp}')),
          style: IconButton.styleFrom(minimumSize: const Size(48, 48)),
          icon: const Icon(Icons.phone_in_talk_rounded),
          tooltip: 'WhatsApp',
        ),
      Expanded(
        child: OutlinedButton.icon(
          onPressed: () => requireLogin(
            context,
            () => openWeb(context, '/chat/start?product_id=$id', title: 'Сообщения'),
          ),
          icon: const Icon(Icons.chat_bubble_outline_rounded, size: 18),
          label: const Text('Написать'),
        ),
      ),
      if (d.auction != null)
        Expanded(
          child: FilledButton(
            onPressed: () => requireLogin(
              context,
              () => openWeb(context, '/auctions/$id/live', title: 'Аукцион'),
            ),
            child: const Text('Сделать ставку'),
          ),
        )
      else if (d.purchasable)
        Expanded(
          child: FilledButton(
            onPressed: () => requireLogin(
              context,
              () async {
                await openWeb(context, '/checkout/$id', title: 'Оформление');
                if (mounted) _load();
              },
            ),
            child: const Text('Купить'),
          ),
        ),
    ];
    return _bar(buttons);
  }

  Widget _bar(List<Widget> children) {
    final withGaps = <Widget>[];
    for (final c in children) {
      if (withGaps.isNotEmpty) withGaps.add(const SizedBox(width: 10));
      withGaps.add(c);
    }
    return SafeArea(
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
        decoration: const BoxDecoration(
          color: Colors.white,
          border: Border(top: BorderSide(color: Color(0xFFE2E8F0))),
        ),
        child: Row(children: withGaps),
      ),
    );
  }

  void _openGallery(List<String> images, int start) {
    Navigator.of(context).push(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white),
        body: PageView.builder(
          controller: PageController(initialPage: start),
          itemCount: images.length,
          itemBuilder: (_, i) => InteractiveViewer(
            child: Center(child: ProductImage(url: images[i], type: '', fit: BoxFit.contain)),
          ),
        ),
      ),
    ));
  }

  static String _statusLabel(String s) => switch (s) {
        'sold' => 'Продано',
        'reserved' => 'Забронировано',
        'out_of_stock' => 'Нет в наличии',
        'archived' => 'В архиве',
        _ => s,
      };

  static String _money(int v) {
    final s = v.toString();
    final buf = StringBuffer();
    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) buf.write(' ');
      buf.write(s[i]);
    }
    return '$buf ₸';
  }
}
