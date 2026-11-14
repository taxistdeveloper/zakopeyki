import 'package:flutter/material.dart';

import '../main.dart';
import '../nav.dart';
import 'cart_screen.dart';
import 'catalog_screen.dart';
import 'favorites_screen.dart';
import 'my_listings_screen.dart';
import 'profile_screen.dart';

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;
  int _cartVisit = 0;

  static const _sellIndex = 2;
  static const _cartIndex = 3;

  void _onTap(int i) {
    if (i == _sellIndex) {
      requireLogin(context, () async {
        final id = await openListingForm(context);
        if (id != null && mounted) openProduct(context, id);
      });
      return;
    }
    setState(() {
      if (i == _cartIndex) _cartVisit++; // корзину перечитываем при каждом открытии вкладки
      _index = i;
    });
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    // Ключи по пользователю: при входе/выходе вкладки создаются заново
    final uid = session.user?.id;
    final cartCount = session.cartCount;

    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: [
          const CatalogScreen(),
          FavoritesScreen(key: ValueKey('fav-$uid')),
          const SizedBox.shrink(),
          CartScreen(key: ValueKey('cart-$uid-$_cartVisit'), embedded: true),
          const ProfileScreen(),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: _onTap,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          const NavigationDestination(icon: Icon(Icons.search_rounded), label: 'Каталог'),
          const NavigationDestination(
            icon: Icon(Icons.favorite_border_rounded),
            selectedIcon: Icon(Icons.favorite_rounded),
            label: 'Избранное',
          ),
          const NavigationDestination(icon: Icon(Icons.add_circle_rounded, size: 32), label: 'Подать'),
          NavigationDestination(
            icon: Badge(
              isLabelVisible: cartCount > 0,
              label: Text('$cartCount'),
              child: const Icon(Icons.shopping_bag_outlined),
            ),
            selectedIcon: Badge(
              isLabelVisible: cartCount > 0,
              label: Text('$cartCount'),
              child: const Icon(Icons.shopping_bag_rounded),
            ),
            label: 'Корзина',
          ),
          const NavigationDestination(
            icon: Icon(Icons.person_outline_rounded),
            selectedIcon: Icon(Icons.person_rounded),
            label: 'Профиль',
          ),
        ],
      ),
    );
  }
}

class LoginPrompt extends StatelessWidget {
  const LoginPrompt({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 56, color: Colors.black26),
              const SizedBox(height: 16),
              Text(text, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 20),
              FilledButton(onPressed: () => openLogin(context), child: const Text('Войти')),
            ],
          ),
        ),
      ),
    );
  }
}
