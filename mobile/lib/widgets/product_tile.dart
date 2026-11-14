import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../models.dart';
import '../theme.dart';

class ProductTile extends StatelessWidget {
  const ProductTile({super.key, required this.item, required this.onTap, this.onFavorite});

  final ProductCard item;
  final VoidCallback onTap;
  final VoidCallback? onFavorite;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(14),
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
                          backgroundColor: Colors.white.withValues(alpha: 0.9),
                          minimumSize: const Size(36, 36),
                        ),
                        icon: Icon(
                          item.favorited ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                          size: 20,
                          color: item.favorited ? Colors.redAccent : Colors.black54,
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
                    style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 2),
                  Text(item.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: text.bodyMedium),
                  if (item.location.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      item.location,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: text.bodySmall?.copyWith(color: Colors.black45),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class ProductImage extends StatelessWidget {
  const ProductImage({super.key, required this.url, required this.type, this.fit = BoxFit.cover});

  final String? url;
  final String type;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    final placeholder = Container(
      color: const Color(0xFFEFF6FF),
      alignment: Alignment.center,
      child: Icon(_typeIcon(type), size: 40, color: kBrand.withValues(alpha: 0.5)),
    );
    if (url == null) return placeholder;
    return CachedNetworkImage(
      imageUrl: url!,
      fit: fit,
      placeholder: (_, _) => Container(color: const Color(0xFFF1F5F9)),
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
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: _colors[type] ?? const Color(0xFFF59E0B),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        label,
        style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600),
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
    mainAxisSpacing: 10,
    crossAxisSpacing: 10,
    childAspectRatio: 0.62,
  );
}
