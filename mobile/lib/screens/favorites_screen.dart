import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../models.dart';
import '../nav.dart';
import '../widgets/product_tile.dart';
import 'home_shell.dart';

class FavoritesScreen extends StatefulWidget {
  const FavoritesScreen({super.key});

  @override
  State<FavoritesScreen> createState() => _FavoritesScreenState();
}

class _FavoritesScreenState extends State<FavoritesScreen> {
  List<ProductCard>? _items;
  String? _error;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_items == null && _error == null && SessionScope.of(context).loggedIn) _load();
  }

  Future<void> _load() async {
    try {
      final items = await SessionScope.read(context).api.favorites();
      if (mounted) {
        setState(() {
          _items = items;
          _error = null;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _remove(ProductCard item) async {
    try {
      await SessionScope.read(context).api.toggleFavorite(item.id);
      if (mounted) setState(() => _items?.remove(item));
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!SessionScope.of(context).loggedIn) {
      return const LoginPrompt(
        icon: Icons.favorite_border_rounded,
        text: 'Войдите, чтобы сохранять понравившиеся объявления',
      );
    }
    final items = _items;
    return Scaffold(
      appBar: AppBar(title: const Text('Избранное')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            if (items == null && _error == null)
              const SliverFillRemaining(child: Center(child: CircularProgressIndicator()))
            else if (_error != null && items == null)
              SliverFillRemaining(child: Center(child: Text(_error!)))
            else if (items!.isEmpty)
              const SliverFillRemaining(
                child: Center(
                  child: Text('Здесь появятся объявления, отмеченные ♥',
                      style: TextStyle(color: Colors.black54)),
                ),
              )
            else
              SliverPadding(
                padding: const EdgeInsets.all(12),
                sliver: SliverGrid.builder(
                  gridDelegate: productGridDelegate(context),
                  itemCount: items.length,
                  itemBuilder: (_, i) => ProductTile(
                    item: items[i],
                    onTap: () async {
                      await openProduct(context, items[i].id);
                      _load();
                    },
                    onFavorite: () => _remove(items[i]),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
