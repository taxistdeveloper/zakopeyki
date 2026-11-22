import 'package:flutter/material.dart';

import '../main.dart';
import '../nav.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/wake_word_ui.dart';
import 'ads_screen.dart';
import 'business_screen.dart';
import 'cabinet_screens.dart';
import 'cart_screen.dart';
import 'chats_screen.dart';
import 'contests_screen.dart';
import 'info_page_screen.dart';
import 'assistant_screen.dart';
import 'live_list_screen.dart';
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
      backgroundColor: context.pal.surface,
      child: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: 8),
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
              child: Row(
                children: [
                  Image.asset('assets/img/coin_small.png', width: 44, height: 44),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Image.asset((context.pal.dark ? 'assets/img/logo_header_dark.png' : 'assets/img/logo_header.png'), height: 26, alignment: Alignment.centerLeft),
                        Text(user?.name ?? 'Маркетплейс Казахстана',
                            maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: context.pal.muted)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const _Header('Меню'),
            _Item(
              icon: Icons.auto_awesome_rounded,
              title: 'ZAK — ИИ-помощник',
              onTap: () {
                Navigator.of(context).pop();
                openAssistant(context);
              },
            ),
            type(Icons.home_outlined, 'Главная', null),
            type(Icons.fiber_new_outlined, 'Новые товары', 'new'),
            type(Icons.recycling_rounded, 'Товары б/у', 'used'),
            type(Icons.gavel_rounded, 'Аукционы', 'auction'),
            _Item(
              icon: Icons.live_tv_rounded,
              title: 'Прямые эфиры',
              onTap: () {
                Navigator.of(context).pop();
                Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LiveListScreen()));
              },
            ),
            type(Icons.handyman_outlined, 'Услуги', 'service'),
            type(Icons.swap_horiz_rounded, 'Бартер', 'exchange'),
            type(Icons.volunteer_activism_outlined, 'Даром', 'free'),
            _Item(icon: Icons.emoji_events_outlined, title: 'Конкурсы', onTap: () => push(const ContestsScreen(), needsLogin: false)),
            _Item(icon: Icons.card_giftcard_rounded, title: 'Бонусы', onTap: () => push(const BonusesScreen())),
            const Divider(height: 24),
            const _Header('Мой аккаунт'),
            _Item(icon: Icons.shopping_bag_outlined, title: 'Корзина', badge: session.cartCount, onTap: () => push(const CartScreen())),
            _Item(icon: Icons.receipt_long_outlined, title: 'Сделки', onTap: () => push(const OrdersScreen())),
            _Item(icon: Icons.sell_outlined, title: 'Мои объявления', onTap: () => push(const MyListingsScreen())),
            _Item(icon: Icons.account_balance_wallet_outlined, title: 'Кошелёк', onTap: () => push(const WalletScreen())),
            _Item(icon: Icons.chat_bubble_outline_rounded, title: 'Сообщения', badge: session.chatUnread, onTap: () => push(const ChatsScreen())),
            _Item(icon: Icons.workspace_premium_outlined, title: 'Бизнес-пакет', onTap: () => push(const BusinessScreen())),
            _Item(icon: Icons.campaign_outlined, title: 'Реклама', onTap: () => push(const AdsScreen())),
            const Divider(height: 24),
            const _Header('Сервис'),
            _Item(
              icon: Icons.dark_mode_outlined,
              title: 'Оформление · ${themeModeLabel(session.themeMode)}',
              onTap: () {
                Navigator.of(context).pop();
                showThemePicker(context);
              },
            ),
            ListenableBuilder(
              listenable: session.wakeWord,
              builder: (context, _) => _Item(
                icon: Icons.record_voice_over_outlined,
                title: 'Голосовое пробуждение · ${session.wakeWord.label}',
                onTap: () {
                  Navigator.of(context).pop();
                  showWakeWordSettings(context);
                },
              ),
            ),
            _Item(
              icon: Icons.info_outline_rounded,
              title: 'О сервисе',
              onTap: () => push(const InfoPageScreen(slug: 'about'), needsLogin: false),
            ),
            _Item(
              icon: Icons.description_outlined,
              title: 'Оферта и политики',
              onTap: () {
                Navigator.of(context).pop();
                showModalBottomSheet<void>(
                  context: context,
                  showDragHandle: true,
                  builder: (ctx) => SafeArea(
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      for (final (slug, title) in const [
                        ('offer', 'Публичная оферта'),
                        ('privacy', 'Политика конфиденциальности'),
                        ('data-policy', 'Регламент сбора данных'),
                        ('listing-rules', 'Правила размещения'),
                      ])
                        ListTile(
                          leading: const Icon(Icons.article_outlined),
                          title: Text(title),
                          onTap: () {
                            Navigator.pop(ctx);
                            openInfoPage(context, slug, title: title);
                          },
                        ),
                    ]),
                  ),
                );
              },
            ),
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
            style: TextStyle(color: context.pal.muted2, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 0.6)),
      );
}

class _Item extends StatelessWidget {
  const _Item({
    required this.icon,
    required this.title,
    required this.onTap,
    this.selected = false,
    this.badge = 0,
  });

  final IconData icon;
  final String title;
  final VoidCallback onTap;
  final bool selected;
  final int badge;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: ListTile(
        dense: true,
        visualDensity: const VisualDensity(vertical: -1),
        selected: selected,
        selectedTileColor: context.pal.brandSoft,
        selectedColor: kBrand,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        leading: Icon(icon, size: 22),
        title: Text(title, style: TextStyle(fontSize: 15, fontWeight: selected ? FontWeight.w700 : FontWeight.w500)),
        trailing: badge > 0 ? Badge(label: Text('$badge')) : null,
        onTap: onTap,
      ),
    );
  }
}
