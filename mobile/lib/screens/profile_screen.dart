import 'package:flutter/material.dart';

import '../main.dart';
import '../nav.dart';
import '../theme.dart';
import 'cart_screen.dart';
import 'home_shell.dart';
import 'my_listings_screen.dart';
import 'orders_screen.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  /// Разделы, для которых в мобильном API пока нет методов, — открываются сайтом.
  static const _webSections = [
    (Icons.account_balance_wallet_outlined, 'Кошелёк', '/wallet'),
    (Icons.notifications_none_rounded, 'Уведомления', '/profile?tab=notifications'),
    (Icons.manage_accounts_outlined, 'Личные данные', '/profile?tab=personal'),
    (Icons.lock_outline_rounded, 'Пароль и 2FA', '/profile?tab=password'),
    (Icons.support_agent_rounded, 'Поддержка', '/support'),
  ];

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final user = session.user;
    if (user == null) {
      return const LoginPrompt(
        icon: Icons.person_outline_rounded,
        text: 'Войдите, чтобы управлять объявлениями, заказами и кошельком',
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Профиль')),
      body: RefreshIndicator(
        onRefresh: session.refreshUser,
        child: ListView(
          children: [
            Container(
              color: Colors.white,
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 30,
                    backgroundImage: user.avatar != null ? NetworkImage(user.avatar!) : null,
                    child: user.avatar == null
                        ? Text(user.avatarLetter, style: const TextStyle(fontSize: 22))
                        : null,
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(user.name,
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w700)),
                        Text(user.email, style: const TextStyle(color: Colors.black54)),
                        if (user.isBusiness)
                          const Padding(
                            padding: EdgeInsets.only(top: 4),
                            child: Text(
                              'Бизнес-аккаунт',
                              style: TextStyle(color: Color(0xFF2563EB), fontSize: 12),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            _group([
              _tile(context, Icons.sell_outlined, 'Мои объявления',
                  () => _push(context, const MyListingsScreen())),
              _tile(context, Icons.shopping_bag_outlined, 'Мои покупки',
                  () => _push(context, const OrdersScreen())),
              _tile(context, Icons.storefront_outlined, 'Мои продажи',
                  () => _push(context, const OrdersScreen(initialRole: 'selling'))),
              _tile(context, Icons.shopping_cart_outlined, 'Корзина',
                  () => _push(context, const CartScreen()),
                  badge: session.cartCount),
            ]),
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 16, 16, 6),
              child: Text('Ещё', style: TextStyle(color: Colors.black54, fontWeight: FontWeight.w600)),
            ),
            _group([
              for (final (icon, title, path) in _webSections)
                _tile(context, icon, title, () => openWeb(context, path, title: title)),
            ]),
            const SizedBox(height: 8),
            Container(
              color: Colors.white,
              child: ListTile(
                leading: const Icon(Icons.logout_rounded, color: Colors.redAccent),
                title: const Text('Выйти', style: TextStyle(color: Colors.redAccent)),
                onTap: () async {
                  final ok = await showDialog<bool>(
                    context: context,
                    builder: (ctx) => AlertDialog(
                      title: const Text('Выйти из аккаунта?'),
                      actions: [
                        TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
                        FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Выйти')),
                      ],
                    ),
                  );
                  if (ok == true) await session.logout();
                },
              ),
            ),
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  static Future<void> _push(BuildContext context, Widget screen) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));

  static Widget _group(List<Widget> tiles) => Container(
        margin: const EdgeInsets.symmetric(horizontal: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xFFE2E8F0)),
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(children: tiles),
      );

  static Widget _tile(BuildContext context, IconData icon, String title, VoidCallback onTap, {int badge = 0}) =>
      ListTile(
        leading: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(color: const Color(0xFFEFF6FF), borderRadius: BorderRadius.circular(10)),
          child: Icon(icon, color: kBrand, size: 20),
        ),
        title: Text(title),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (badge > 0) Badge(label: Text('$badge')),
            const Icon(Icons.chevron_right_rounded),
          ],
        ),
        onTap: onTap,
      );
}
