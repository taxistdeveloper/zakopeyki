import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../../../l10n/app_localizations.dart';
import '../listing_type_label.dart';
import '../models.dart';

class ProductTile extends StatelessWidget {
  const ProductTile({super.key, required this.product, required this.onTap, this.onFavorite});

  final ProductCard product;
  final VoidCallback onTap;
  final VoidCallback? onFavorite;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Semantics(
      button: true,
      label: '${product.title}, ${product.priceText}',
      child: Card(
        color: theme.colorScheme.surfaceContainerLow,
        child: InkWell(
          onTap: onTap,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Картинка занимает оставшееся место — текст под ней никогда не переполняет карточку
              // (в т.ч. при увеличенном системном шрифте).
              Expanded(
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    ProductImage(url: product.coverUrl),
                    Positioned(
                      left: 8,
                      top: 8,
                      child: _Badge(text: listingTypeLabel(context, product.type, product.typeLabel)),
                    ),
                    if (onFavorite != null)
                      Positioned(
                        right: 4,
                        top: 4,
                        child: IconButton.filledTonal(
                          visualDensity: VisualDensity.compact,
                          tooltip: AppLocalizations.of(context).navFavorites,
                          onPressed: onFavorite,
                          icon: Icon(
                            product.isFavorite ? Icons.favorite : Icons.favorite_border,
                            color: product.isFavorite ? Colors.redAccent : null,
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.priceText,
                      style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 2),
                    // Фиксированная высота 2 строк заголовка и строки города: картинки в ряду одной высоты
                    SizedBox(
                      height: _lineHeight(context, theme.textTheme.bodyMedium) * 2,
                      child: Text(product.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.bodyMedium),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      product.location ?? '',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

double _lineHeight(BuildContext context, TextStyle? style) {
  final size = style?.fontSize ?? 14;
  return MediaQuery.textScalerOf(context).scale(size) * (style?.height ?? 1.43);
}

class ProductImage extends StatelessWidget {
  const ProductImage({super.key, required this.url, this.fit = BoxFit.cover});
  final String? url;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    final bg = Theme.of(context).colorScheme.surfaceContainerHighest;
    final placeholder = ColoredBox(
      color: bg,
      child: Icon(Icons.image_outlined, size: 40, color: Theme.of(context).colorScheme.outline),
    );
    if (url == null) return placeholder;
    return CachedNetworkImage(
      imageUrl: url!,
      fit: fit,
      // экономия памяти: оригиналы на сервере без превью
      memCacheWidth: 600,
      placeholder: (_, _) => ColoredBox(color: bg),
      errorWidget: (_, _, _) => placeholder,
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.55), borderRadius: BorderRadius.circular(8)),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        child: Text(text, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600)),
      ),
    );
  }
}
