import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../models.dart';
import '../theme.dart';

class ProductTile extends StatelessWidget {
  const ProductTile({
    super.key,
    required this.item,
    required this.onTap,
    this.onFavorite,
  });

  final ProductCard item;
  final VoidCallback onTap;
  final VoidCallback? onFavorite;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return DecoratedBox(
      decoration: softCard(context, radius: kRadius),
      child: Material(
        color: context.pal.surface,
        borderRadius: BorderRadius.circular(kRadius),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              AspectRatio(
                aspectRatio: 1,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    ProductImage(url: item.image, type: item.type),
                    Positioned(
                      left: 8,
                      top: 8,
                      child: TypeBadge(type: item.type, label: item.typeLabel),
                    ),
                    if (onFavorite != null)
                      Positioned(
                        right: 4,
                        top: 4,
                        child: IconButton(
                          onPressed: onFavorite,
                          style: IconButton.styleFrom(
                            backgroundColor: context.pal.surface.withValues(
                              alpha: 0.92,
                            ),
                            minimumSize: const Size(36, 36),
                            elevation: 1,
                            shadowColor: const Color(0x331E3A8A),
                          ),
                          icon: Icon(
                            item.favorited
                                ? Icons.favorite_rounded
                                : Icons.favorite_border_rounded,
                            size: 20,
                            color: item.favorited
                                ? Colors.redAccent
                                : context.pal.muted,
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
                      item.priceText,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: display(
                        16.5,
                        weight: FontWeight.w800,
                        color: context.pal.ink,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      item.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: text.bodyMedium?.copyWith(
                        color: context.pal.ink2,
                        height: 1.25,
                      ),
                    ),
                    if (item.location.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        item.location,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: text.bodySmall?.copyWith(color: context.pal.muted2),
                      ),
                    ],
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

class ProductImage extends StatelessWidget {
  const ProductImage({
    super.key,
    required this.url,
    required this.type,
    this.fit = BoxFit.cover,
  });

  final String? url;
  final String type;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    final placeholder = Container(
      color: context.pal.brandSoft,
      alignment: Alignment.center,
      child: Icon(
        _typeIcon(type),
        size: 40,
        color: kBrand.withValues(alpha: 0.5),
      ),
    );
    if (url == null) return placeholder;
    return CachedNetworkImage(
      imageUrl: url!,
      fit: fit,
      placeholder: (_, _) => Container(color: context.pal.subtle),
      errorWidget: (_, _, _) => placeholder,
    );
  }
}

IconData _typeIcon(String type) => switch (type) {
  'auction' => Icons.gavel_rounded,
  'service' => Icons.handyman_rounded,
  'exchange' => Icons.swap_horiz_rounded,
  'free' => Icons.volunteer_activism_rounded,
  _ => Icons.inventory_2_outlined,
};

class TypeBadge extends StatelessWidget {
  const TypeBadge({super.key, required this.type, required this.label});

  final String type;
  final String label;

  static const _colors = {
    'used': Color(0xFFF97316),
    'new': Color(0xFF2563EB),
    'auction': Color(0xFFEF4444),
    'free': Color(0xFF0EA5E9),
    'exchange': Color(0xFF6366F1),
    'service': Color(0xFF10B981),
    'gig': Color(0xFF0D9488),
  };

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: _colors[type] ?? const Color(0xFFF59E0B),
        borderRadius: BorderRadius.circular(999),
        boxShadow: [
          BoxShadow(
            color: (_colors[type] ?? kGold).withValues(alpha: 0.35),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: Colors.white,
          fontSize: 11,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

/// Сетка карточек для Sliver-списков.
SliverGridDelegate productGridDelegate(BuildContext context) {
  final width = MediaQuery.sizeOf(context).width;
  final columns = width > 900 ? 4 : (width > 600 ? 3 : 2);
  return SliverGridDelegateWithFixedCrossAxisCount(
    crossAxisCount: columns,
    mainAxisSpacing: 12,
    crossAxisSpacing: 12,
    childAspectRatio: 0.63,
  );
}
