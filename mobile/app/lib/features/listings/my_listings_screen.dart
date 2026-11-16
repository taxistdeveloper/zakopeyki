import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/feed_controller.dart';
import '../catalog/listing_type_label.dart';
import '../catalog/widgets/product_tile.dart';
import 'listings_repository.dart';

class MyListingsScreen extends ConsumerWidget {
  const MyListingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l.myListings)),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/listing/new'),
        icon: const Icon(Icons.add),
        label: Text(l.newListing),
      ),
      body: ref.watch(myListingsProvider).when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(myListingsProvider)),
            data: (items) => items.isEmpty
                ? MessageView(icon: Icons.inventory_2_outlined, title: l.emptyMyListings)
                : RefreshIndicator(
                    onRefresh: () => ref.refresh(myListingsProvider.future),
                    child: ListView.separated(
                      padding: const EdgeInsets.fromLTRB(12, 8, 12, 96),
                      itemCount: items.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 8),
                      itemBuilder: (context, i) => _MyListingTile(item: items[i]),
                    ),
                  ),
          ),
    );
  }
}

class _MyListingTile extends ConsumerWidget {
  const _MyListingTile({required this.item});
  final MyListing item;

  String _status(AppLocalizations l) => switch (item.status) {
        'active' => l.statusActive,
        'sold' => l.statusSold,
        'archived' => l.statusArchived,
        'reserved' => l.statusReserved,
        'out_of_stock' => l.statusOutOfStock,
        _ => item.status,
      };

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final l = AppLocalizations.of(context);
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l.deleteListing),
        content: Text(l.deleteListingConfirm),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.delete)),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await ref.read(listingsRepositoryProvider).delete(item.card.id);
      ref.invalidate(myListingsProvider);
      ref.read(feedControllerProvider.notifier).refresh();
      scaffoldMessengerKey.currentState?.showSnackBar(SnackBar(content: Text(l.listingDeleted)));
    } on ApiException catch (e) {
      if (context.mounted) {
        scaffoldMessengerKey.currentState?.showSnackBar(SnackBar(content: Text(errorText(context, e))));
      }
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final card = item.card;
    return Card(
      color: theme.colorScheme.surfaceContainerLow,
      child: InkWell(
        onTap: () => context.push('/product/${card.id}'),
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: Row(children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: SizedBox(width: 84, height: 84, child: ProductImage(url: card.coverUrl)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(card.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.titleSmall),
                const SizedBox(height: 4),
                Text(card.priceText, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Text(
                  '${listingTypeLabel(context, card.type, card.typeLabel)} · ${_status(l)} · ${l.views(item.viewCount)}',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ]),
            ),
            PopupMenuButton<String>(
              tooltip: MaterialLocalizations.of(context).showMenuTooltip,
              onSelected: (v) {
                if (v == 'edit') {
                  if (item.editableInApp) {
                    context.push('/listing/${card.id}/edit');
                  } else {
                    scaffoldMessengerKey.currentState?.showSnackBar(SnackBar(content: Text(l.editOnSite)));
                  }
                }
                if (v == 'delete') _delete(context, ref);
              },
              itemBuilder: (_) => [
                PopupMenuItem(value: 'edit', child: Text(l.editListing)),
                PopupMenuItem(value: 'delete', child: Text(l.deleteListing)),
              ],
            ),
          ]),
        ),
      ),
    );
  }
}
