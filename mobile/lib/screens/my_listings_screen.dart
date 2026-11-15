import 'package:flutter/material.dart';

import '../theme.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../widgets/common.dart';
import 'listing_form_screen.dart';

class MyListingsScreen extends StatefulWidget {
  const MyListingsScreen({super.key});

  @override
  State<MyListingsScreen> createState() => _MyListingsScreenState();
}

class _MyListingsScreenState extends State<MyListingsScreen> {
  List<MyListing>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final items = await SessionScope.read(context).api.myListings();
      if (mounted) setState(() => _items = items);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _create() async {
    final id = await openListingForm(context);
    if (id != null && mounted) _load();
  }

  Future<void> _menu(MyListing l) async {
    final action = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.visibility_outlined),
              title: const Text('Открыть объявление'),
              onTap: () => Navigator.pop(ctx, 'open'),
            ),
            if (l.editableInApp)
              ListTile(
                leading: const Icon(Icons.edit_outlined),
                title: const Text('Редактировать'),
                onTap: () => Navigator.pop(ctx, 'edit'),
              )
            else
              ListTile(
                leading: const Icon(Icons.open_in_browser_rounded),
                title: const Text('Редактировать на сайте'),
                subtitle: const Text('Этот тип объявления пока редактируется только на сайте'),
                onTap: () => Navigator.pop(ctx, 'web'),
              ),
            ListTile(
              leading: const Icon(Icons.delete_outline_rounded, color: Color(0xFFDC2626)),
              title: const Text('Удалить', style: TextStyle(color: Color(0xFFDC2626))),
              onTap: () => Navigator.pop(ctx, 'delete'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || action == null) return;
    switch (action) {
      case 'open':
        await openProduct(context, l.card.id);
      case 'edit':
        if (await openListingForm(context, editId: l.card.id) != null) _load();
      case 'web':
        await openWeb(context, '/profile?tab=lots', title: 'Мои объявления');
        _load();
      case 'delete':
        final ok = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Удалить объявление?'),
            content: Text('«${l.card.title}» будет удалено без возможности восстановления.'),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
              FilledButton(
                style: FilledButton.styleFrom(backgroundColor: const Color(0xFFDC2626)),
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Удалить'),
              ),
            ],
          ),
        );
        if (ok != true || !mounted) return;
        try {
          await SessionScope.read(context).api.deleteListing(l.card.id);
          if (mounted) {
            showSnack(context, 'Объявление удалено');
            _load();
          }
        } on ApiException catch (e) {
          if (mounted) showSnack(context, e.message);
        }
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    Widget body;
    if (items == null) {
      body = _error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    } else if (items.isEmpty) {
      body = EmptyView(
        icon: Icons.sell_outlined,
        title: 'У вас пока нет объявлений',
        text: 'Продайте то, что больше не нужно — это займёт пару минут',
        action: FilledButton.icon(onPressed: _create, icon: const Icon(Icons.add_rounded), label: const Text('Подать объявление')),
      );
    } else {
      body = RefreshIndicator(
        onRefresh: _load,
        child: ListView.builder(
          padding: const EdgeInsets.only(top: 12, bottom: 88),
          itemCount: items.length,
          itemBuilder: (_, i) {
            final l = items[i];
            return GestureDetector(
              onTap: () => _menu(l),
              child: SectionCard(
                padding: const EdgeInsets.all(12),
                child: Row(
                  children: [
                    ProductThumb(l.card.image, size: 72),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(l.card.title, maxLines: 2, overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontWeight: FontWeight.w600)),
                          const SizedBox(height: 2),
                          Text(l.card.priceText, style: const TextStyle(fontWeight: FontWeight.w800)),
                          const SizedBox(height: 6),
                          Row(
                            children: [
                              StatusPill(
                                listingStatusLabel(l.status),
                                color: l.status == 'active' ? const Color(0xFF16A34A) : context.pal.muted,
                              ),
                              const SizedBox(width: 8),
                              Icon(Icons.visibility_outlined, size: 15, color: context.pal.faint),
                              const SizedBox(width: 3),
                              Text('${l.views}', style: TextStyle(color: context.pal.muted2, fontSize: 12.5)),
                            ],
                          ),
                        ],
                      ),
                    ),
                    Icon(Icons.more_vert_rounded, color: context.pal.faint),
                  ],
                ),
              ),
            );
          },
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Мои объявления')),
      body: body,
      floatingActionButton: items != null && items.isNotEmpty
          ? FloatingActionButton.extended(
              onPressed: _create,
              icon: const Icon(Icons.add_rounded),
              label: const Text('Подать'),
            )
          : null,
    );
  }
}

/// Открыть форму подачи / правки. Возвращает id объявления, если сохранено.
Future<int?> openListingForm(BuildContext context, {int? editId}) {
  return Navigator.of(context).push<int>(MaterialPageRoute(
    fullscreenDialog: editId == null,
    builder: (_) => ListingFormScreen(editId: editId),
  ));
}
