import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../commerce/commerce_repository.dart';
import '../../l10n/app_localizations.dart';
import 'catalog_repository.dart';
import 'favorite_action.dart';
import 'listing_type_label.dart';
import 'models.dart';
import 'widgets/product_tile.dart';

class ProductScreen extends ConsumerWidget {
  const ProductScreen({super.key, required this.productId});
  final int productId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(productDetailProvider(productId));
    return async.when(
      loading: () => Scaffold(appBar: AppBar(), body: const Center(child: CircularProgressIndicator())),
      error: (e, _) => Scaffold(
        appBar: AppBar(),
        body: ErrorView(error: e, onRetry: () => ref.invalidate(productDetailProvider(productId))),
      ),
      data: (p) => _ProductBody(product: p),
    );
  }
}

class _ProductBody extends ConsumerWidget {
  const _ProductBody({required this.product});
  final ProductDetail product;

  void _requireLogin(BuildContext context, WidgetRef ref, VoidCallback then) {
    if (ref.read(authControllerProvider) is AuthAuthenticated) {
      then();
    } else {
      context.push('/login');
    }
  }

  Future<void> _addToCart(BuildContext context, WidgetRef ref) async {
    final l = AppLocalizations.of(context);
    _requireLogin(context, ref, () async {
      try {
        final cart = await ref.read(commerceRepositoryProvider).add(product.card.id);
        ref.invalidate(cartProvider);
        scaffoldMessengerKey.currentState?.showSnackBar(SnackBar(content: Text(cart.notice ?? l.addedToCart)));
      } on ApiException catch (e) {
        if (context.mounted) {
          scaffoldMessengerKey.currentState?.showSnackBar(SnackBar(content: Text(errorText(context, e))));
        }
      }
    });
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final card = product.card;
    final safeDeal = ref.watch(remoteConfigProvider).value?.safeDealPercent;
    final width = MediaQuery.sizeOf(context).width;

    return Scaffold(
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            expandedHeight: width.clamp(240, 480).toDouble(),
            pinned: true,
            actions: [
              IconButton(
                tooltip: l.navFavorites,
                onPressed: () => toggleFavorite(context, ref, card.id),
                icon: Icon(card.isFavorite ? Icons.favorite : Icons.favorite_border,
                    color: card.isFavorite ? Colors.redAccent : null),
              ),
            ],
            flexibleSpace: FlexibleSpaceBar(background: _Gallery(images: product.images)),
          ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
            sliver: SliverList.list(children: [
              Wrap(spacing: 8, runSpacing: 8, children: [
                Chip(label: Text(listingTypeLabel(context, card.type, card.typeLabel)), visualDensity: VisualDensity.compact),
                if (card.categoryLabel != null)
                  Chip(label: Text(card.categoryLabel!), visualDensity: VisualDensity.compact),
              ]),
              const SizedBox(height: 8),
              Text(card.title, style: theme.textTheme.headlineSmall),
              const SizedBox(height: 8),
              Text(card.priceText,
                  style: theme.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800, color: theme.colorScheme.primary)),
              if (product.auction?.endsAt != null) ...[
                const SizedBox(height: 4),
                Text(l.auctionEnds(DateFormat.yMMMd(Localizations.localeOf(context).toString()).add_Hm().format(product.auction!.endsAt!.toLocal()))),
              ],
              if (product.quantityAvailable != null && product.quantityAvailable! > 1) ...[
                const SizedBox(height: 4),
                Text(l.inStock(product.quantityAvailable!), style: theme.textTheme.bodyMedium),
              ],
              if (card.location != null) ...[
                const SizedBox(height: 8),
                Row(children: [
                  const Icon(Icons.place_outlined, size: 18),
                  const SizedBox(width: 4),
                  Expanded(child: Text(card.location!)),
                ]),
              ],
              if (product.canBuy && safeDeal != null && safeDeal > 0) ...[
                const SizedBox(height: 16),
                _InfoBox(icon: Icons.verified_user_outlined, text: l.safeDealInfo(safeDeal)),
              ],
              if (product.exchangeFor != null) ...[
                const SizedBox(height: 16),
                Text(l.exchangeFor, style: theme.textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(product.exchangeFor!),
              ],
              if (product.description.isNotEmpty) ...[
                const SizedBox(height: 20),
                Text(l.description, style: theme.textTheme.titleMedium),
                const SizedBox(height: 6),
                SelectableText(product.description, style: theme.textTheme.bodyLarge),
              ],
              if (product.characteristics.isNotEmpty) ...[
                const SizedBox(height: 20),
                Text(l.characteristics, style: theme.textTheme.titleMedium),
                const SizedBox(height: 6),
                for (final c in product.characteristics)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Expanded(child: Text(c.name, style: TextStyle(color: theme.colorScheme.onSurfaceVariant))),
                      const SizedBox(width: 12),
                      Expanded(child: Text(c.value)),
                    ]),
                  ),
              ],
              const SizedBox(height: 20),
              Text(l.seller, style: theme.textTheme.titleMedium),
              const SizedBox(height: 6),
              _SellerTile(seller: card.seller),
              const SizedBox(height: 12),
              Text(l.views(product.viewCount), style: theme.textTheme.bodySmall),
            ]),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(16, 8, 16, 12),
        child: product.canBuy
            ? Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                    onPressed: () => _addToCart(context, ref),
                    icon: const Icon(Icons.add_shopping_cart),
                    label: Text(l.addToCart),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton(
                    onPressed: () => _requireLogin(context, ref, () => context.push('/checkout/product/${card.id}')),
                    child: Text(l.buyNow),
                  ),
                ),
              ])
            : FilledButton.icon(
                onPressed: product.webUrl.isEmpty
                    ? null
                    : () => launchUrl(Uri.parse(product.webUrl), mode: LaunchMode.inAppBrowserView),
                icon: const Icon(Icons.open_in_new),
                label: Text(l.openOnSite),
              ),
      ),
    );
  }
}

class _Gallery extends StatefulWidget {
  const _Gallery({required this.images});
  final List<String> images;

  @override
  State<_Gallery> createState() => _GalleryState();
}

class _GalleryState extends State<_Gallery> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    if (widget.images.isEmpty) return const ProductImage(url: null);
    return Stack(fit: StackFit.expand, children: [
      PageView.builder(
        itemCount: widget.images.length,
        onPageChanged: (i) => setState(() => _index = i),
        itemBuilder: (_, i) => ProductImage(url: widget.images[i]),
      ),
      if (widget.images.length > 1)
        Positioned(
          bottom: 12,
          left: 0,
          right: 0,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var i = 0; i < widget.images.length; i++)
                AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  margin: const EdgeInsets.symmetric(horizontal: 3),
                  width: i == _index ? 18 : 7,
                  height: 7,
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: i == _index ? 1 : 0.6),
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
            ],
          ),
        ),
    ]);
  }
}

class _SellerTile extends StatelessWidget {
  const _SellerTile({required this.seller});
  final SellerSummary seller;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    return Card(
      color: theme.colorScheme.surfaceContainerLow,
      child: ListTile(
        leading: CircleAvatar(
          backgroundImage: seller.avatarUrl != null ? CachedNetworkImageProvider(seller.avatarUrl!) : null,
          child: seller.avatarUrl == null ? Text(seller.name.isNotEmpty ? seller.name.characters.first : '?') : null,
        ),
        title: Text(seller.name, maxLines: 2, overflow: TextOverflow.ellipsis),
        subtitle: Row(children: [
          const Icon(Icons.star_rounded, size: 18, color: Colors.amber),
          const SizedBox(width: 2),
          Text(seller.ratingCount > 0
              ? '${seller.ratingAverage.toStringAsFixed(1)} · ${l.ratingCount(seller.ratingCount)}'
              : l.ratingCount(0)),
        ]),
        trailing: seller.isBusiness ? Chip(label: Text(l.business), visualDensity: VisualDensity.compact) : null,
      ),
    );
  }
}

class _InfoBox extends StatelessWidget {
  const _InfoBox({required this.icon, required this.text});
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return DecoratedBox(
      decoration: BoxDecoration(color: scheme.primaryContainer, borderRadius: BorderRadius.circular(14)),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, color: scheme.onPrimaryContainer),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: TextStyle(color: scheme.onPrimaryContainer))),
        ]),
      ),
    );
  }
}
