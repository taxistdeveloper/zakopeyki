import 'package:flutter/material.dart';

import '../main.dart';
import '../nav.dart';
import 'home_shell.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  static const _sections = [
    (Icons.inventory_2_outlined, 'Мои объявления', '/profile?tab=lots'),
    (Icons.shopping_cart_outlined, 'Корзина', '/cart'),
    (Icons.receipt_long_outlined, 'Заказы и сделки', '/orders'),
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
                        if (user.accountType == 'business')
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text(
                              user.businessStatus == 'verified'
                                  ? 'Бизнес-аккаунт'
                                  : 'Бизнес-аккаунт · на проверке',
                              style: const TextStyle(color: Color(0xFF2563EB), fontSize: 12),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Container(
              color: Colors.white,
              child: Column(
                children: [
                  for (final (icon, title, path) in _sections)
                    ListTile(
                      leading: Icon(icon),
                      title: Text(title),
                      trailing: const Icon(Icons.chevron_right_rounded),
                      onTap: () => openWeb(context, path, title: title),
                    ),
                ],
              ),
            ),
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
}
