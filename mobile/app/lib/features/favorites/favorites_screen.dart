import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/catalog_repository.dart';
import '../catalog/favorite_action.dart';
import '../catalog/widgets/product_tile.dart';

class FavoritesScreen extends ConsumerWidget {
  const FavoritesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l.navFavorites)),
      body: switch (auth) {
        AuthUnknown() => const Center(child: CircularProgressIndicator()),
        AuthGuest() => MessageView(
            icon: Icons.favorite_border,
            title: l.loginRequiredFavorites,
            action: FilledButton(onPressed: () => context.push('/login'), child: Text(l.loginButton)),
          ),
        AuthAuthenticated() => ref.watch(favoritesProvider).when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(favoritesProvider)),
              data: (items) => items.isEmpty
                  ? MessageView(icon: Icons.favorite_border, title: l.emptyFavorites)
                  : RefreshIndicator(
                      onRefresh: () => ref.refresh(favoritesProvider.future),
                      child: GridView.builder(
                        padding: const EdgeInsets.all(12),
                        gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                          maxCrossAxisExtent: 260,
                          mainAxisSpacing: 10,
                          crossAxisSpacing: 10,
                          childAspectRatio: 0.64,
                        ),
                        itemCount: items.length,
                        itemBuilder: (context, i) => ProductTile(
                          product: items[i],
                          onTap: () => context.push('/product/${items[i].id}'),
                          onFavorite: () => toggleFavorite(context, ref, items[i].id),
                        ),
                      ),
                    ),
            ),
      },
    );
  }
}
