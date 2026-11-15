import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'account_screens.dart';
import 'ads_screen.dart';
import 'business_screen.dart';
import 'cabinet_screens.dart';
import 'contests_screen.dart';
import 'cart_screen.dart';
import 'delete_account_screen.dart';
import 'home_shell.dart';
import 'my_listings_screen.dart';
import 'notifications_screen.dart';
import 'orders_screen.dart';
import 'seller_screen.dart';
import 'support_screens.dart';
import 'wallet_screen.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

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
              margin: const EdgeInsets.fromLTRB(14, 4, 14, 0),
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                gradient: kBrandGradient,
                borderRadius: BorderRadius.circular(kRadiusLg),
                boxShadow: kLiftShadow,
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 32,
                    backgroundColor: Colors.white,
                    // буква под фото: видна, пока фото грузится или если его нет
                    foregroundImage: user.avatar != null ? NetworkImage(user.avatar!) : null,
                    child: Text(user.avatarLetter,
                        style: const TextStyle(fontSize: 22, color: kBrand, fontWeight: FontWeight.w700)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(user.name, style: display(19, weight: FontWeight.w700, color: Colors.white)),
                        Text(user.email, style: const TextStyle(color: Colors.white70)),
                        if (user.isBusiness)
                          Container(
                            margin: const EdgeInsets.only(top: 6),
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(999)),
                            child: const Text('✓ Бизнес-аккаунт',
                                style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 14),
            if (!user.emailVerified) const _VerifyEmailBanner(),
            _group(context, [
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
            Padding(
              padding: EdgeInsets.fromLTRB(16, 16, 16, 6),
              child: Text('Ещё', style: TextStyle(color: context.pal.muted, fontWeight: FontWeight.w600)),
            ),
            _group(context, [
              _tile(context, Icons.account_balance_wallet_outlined, 'Кошелёк',
                  () => _push(context, const WalletScreen())),
              _tile(context, Icons.notifications_none_rounded, 'Уведомления',
                  () => _push(context, const NotificationsScreen())),
              _tile(context, Icons.storefront_outlined, 'Мой профиль продавца',
                  () => _push(context, SellerScreen(id: user.id))),
              _tile(context, Icons.star_outline_rounded, 'Отзывы обо мне',
                  () => _push(context, const MyReviewsScreen())),
              _tile(context, Icons.people_outline_rounded, 'Подписки',
                  () => _push(context, const FollowsScreen())),
              _tile(context, Icons.card_giftcard_rounded, 'Бонусы',
                  () => _push(context, const BonusesScreen())),
              _tile(context, Icons.person_add_alt_rounded, 'Пригласить друзей',
                  () => _push(context, const ReferralScreen())),
              _tile(context, Icons.emoji_events_outlined, 'Конкурсы и розыгрыши',
                  () => _push(context, const ContestsScreen())),
              _tile(context, Icons.workspace_premium_outlined, 'Бизнес-аккаунт',
                  () => _push(context, const BusinessScreen())),
              _tile(context, Icons.campaign_outlined, 'Реклама', () => _push(context, const AdsScreen())),
              _tile(context, Icons.manage_accounts_outlined, 'Личные данные',
                  () => _push(context, const PersonalDataScreen())),
              _tile(context, Icons.lock_outline_rounded, 'Пароль и 2FA',
                  () => _push(context, const SecurityScreen())),
              _tile(context, Icons.support_agent_rounded, 'Поддержка',
                  () => _push(context, const SupportListScreen())),
              _tile(context, Icons.dark_mode_outlined, 'Оформление · ${themeModeLabel(SessionScope.of(context).themeMode)}',
                  () => showThemePicker(context)),
            ]),
            const SizedBox(height: 8),
            Container(
              color: context.pal.surface,
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
            Center(
              child: TextButton(
                onPressed: () => _push(context, const DeleteAccountScreen()),
                style: TextButton.styleFrom(foregroundColor: context.pal.muted2),
                child: const Text('Удалить аккаунт'),
              ),
            ),
            const SizedBox(height: 16),
          ],
        ),
      ),
    );
  }

  static Future<void> _push(BuildContext context, Widget screen) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));

  static Widget _group(BuildContext context, List<Widget> tiles) => Container(
        margin: const EdgeInsets.symmetric(horizontal: 14),
        decoration: softCard(context),
        clipBehavior: Clip.antiAlias,
        child: Column(children: tiles),
      );

  static Widget _tile(BuildContext context, IconData icon, String title, VoidCallback onTap, {int badge = 0}) =>
      ListTile(
        leading: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(color: context.pal.brandSoft, borderRadius: BorderRadius.circular(10)),
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


/// «Подтвердите email» + повторная отправка письма.
class _VerifyEmailBanner extends StatefulWidget {
  const _VerifyEmailBanner();

  @override
  State<_VerifyEmailBanner> createState() => _VerifyEmailBannerState();
}

class _VerifyEmailBannerState extends State<_VerifyEmailBanner> {
  bool _busy = false;
  bool _sent = false;

  Future<void> _resend() async {
    setState(() => _busy = true);
    final session = SessionScope.read(context);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final msg = await session.api.resendVerification();
      if (!mounted) return;
      setState(() => _sent = true);
      messenger.showSnackBar(SnackBar(content: Text(msg), behavior: SnackBarBehavior.floating));
      session.refreshUser();
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message), behavior: SnackBarBehavior.floating));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 8),
      padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
      decoration: BoxDecoration(
        color: const Color(0x1FF59E0B),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0x66F59E0B)),
      ),
      child: Row(
        children: [
          const Icon(Icons.mark_email_unread_outlined, color: Color(0xFFD97706)),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              _sent ? 'Письмо отправлено — откройте ссылку из него' : 'Подтвердите email, чтобы не потерять доступ к аккаунту',
              style: const TextStyle(fontSize: 13.5),
            ),
          ),
          TextButton(
            onPressed: _busy ? null : _resend,
            child: Text(_sent ? 'Ещё раз' : 'Отправить'),
          ),
        ],
      ),
    );
  }
}
