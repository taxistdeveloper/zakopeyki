import 'package:flutter/material.dart';

import '../main.dart';
import '../nav.dart';
import '../theme.dart';
import 'cart_screen.dart';
import 'chats_screen.dart';
import 'my_listings_screen.dart';
import 'orders_screen.dart';
import 'support_screens.dart';
import 'wallet_screen.dart';

/// Боковое меню — те же разделы, что в сайдбаре сайта.
/// [onType] переключает ленту каталога (null — «Главная», все объявления).
class AppDrawer extends StatelessWidget {
  const AppDrawer({super.key, required this.currentType, required this.onType});

  final String? currentType;
  final ValueChanged<String?> onType;

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final user = session.user;

    void push(Widget screen, {bool needsLogin = true}) {
      Navigator.of(context).pop();
      if (needsLogin) {
        requireLogin(context, () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen)));
      } else {
        Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
      }
    }

    void web(String path, String title) {
      Navigator.of(context).pop();
      openWeb(context, path, title: title);
    }

    Widget type(IconData icon, String title, String? code) => _Item(
          icon: icon,
          title: title,
          selected: currentType == code,
          onTap: () {
            Navigator.of(context).pop();
            onType(code);
          },
        );

    return Drawer(
      backgroundColor: Colors.white,
      child: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: 8),
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
              child: Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(color: kBrand, borderRadius: BorderRadius.circular(12)),
                    alignment: Alignment.center,
                    child: const Text('З', style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Закопейки', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
                        Text(user?.name ?? 'Маркетплейс Казахстана',
                            maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.black54)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const _Header('Меню'),
            type(Icons.home_outlined, 'Главная', null),
            type(Icons.fiber_new_outlined, 'Новые товары', 'new'),
            type(Icons.recycling_rounded, 'Товары б/у', 'used'),
            type(Icons.gavel_rounded, 'Аукционы', 'auction'),
            type(Icons.handyman_outlined, 'Услуги', 'service'),
            type(Icons.swap_horiz_rounded, 'Бартер', 'exchange'),
            type(Icons.volunteer_activism_outlined, 'Даром', 'free'),
            _Item(icon: Icons.emoji_events_outlined, title: 'Конкурсы', external: true, onTap: () => web('/contests', 'Конкурсы')),
            _Item(icon: Icons.card_giftcard_rounded, title: 'Бонусы', external: true, onTap: () => web('/bonuses', 'Бонусы')),
            const Divider(height: 24),
            const _Header('Мой аккаунт'),
            _Item(icon: Icons.shopping_bag_outlined, title: 'Корзина', badge: session.cartCount, onTap: () => push(const CartScreen())),
            _Item(icon: Icons.receipt_long_outlined, title: 'Сделки', onTap: () => push(const OrdersScreen())),
            _Item(icon: Icons.sell_outlined, title: 'Мои объявления', onTap: () => push(const MyListingsScreen())),
            _Item(icon: Icons.account_balance_wallet_outlined, title: 'Кошелёк', onTap: () => push(const WalletScreen())),
            _Item(icon: Icons.chat_bubble_outline_rounded, title: 'Сообщения', badge: session.chatUnread, onTap: () => push(const ChatsScreen())),
            _Item(
              icon: Icons.workspace_premium_outlined,
              title: 'Бизнес-пакет',
              external: true,
              onTap: () => web('/business/package', 'Бизнес-пакет'),
            ),
            _Item(icon: Icons.campaign_outlined, title: 'Реклама', external: true, onTap: () => web('/ads', 'Реклама')),
            const Divider(height: 24),
            const _Header('Сервис'),
            _Item(icon: Icons.info_outline_rounded, title: 'О сервисе', external: true, onTap: () => web('/about', 'О сервисе')),
            _Item(
              icon: Icons.lightbulb_outline_rounded,
              title: 'Предложить идею',
              onTap: () => push(const SupportListScreen(newTicketCategory: 'idea')),
            ),
            _Item(icon: Icons.support_agent_rounded, title: 'Помощь', onTap: () => push(const SupportListScreen())),
            if (user == null) ...[
              const Divider(height: 24),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: FilledButton(
                  onPressed: () {
                    Navigator.of(context).pop();
                    openLogin(context);
                  },
                  child: const Text('Войти или зарегистрироваться'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 6),
        child: Text(text.toUpperCase(),
            style: const TextStyle(color: Colors.black45, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 0.6)),
      );
}

class _Item extends StatelessWidget {
  const _Item({
    required this.icon,
    required this.title,
    required this.onTap,
    this.selected = false,
    this.external = false,
    this.badge = 0,
  });

  final IconData icon;
  final String title;
  final VoidCallback onTap;
  final bool selected;
  final bool external;
  final int badge;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: ListTile(
        dense: true,
        visualDensity: const VisualDensity(vertical: -1),
        selected: selected,
        selectedTileColor: const Color(0xFFEFF6FF),
        selectedColor: kBrand,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        leading: Icon(icon, size: 22),
        title: Text(title, style: TextStyle(fontSize: 15, fontWeight: selected ? FontWeight.w700 : FontWeight.w500)),
        trailing: badge > 0
            ? Badge(label: Text('$badge'))
            : (external ? const Icon(Icons.open_in_new_rounded, size: 16, color: Colors.black26) : null),
        onTap: onTap,
      ),
    );
  }
}
