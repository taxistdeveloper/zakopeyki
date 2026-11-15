import 'dart:async';

import 'package:flutter/material.dart';

import '../theme.dart';

import '../main.dart';
import '../nav.dart';
import 'catalog_screen.dart';
import 'chats_screen.dart';
import 'favorites_screen.dart';
import 'my_listings_screen.dart';
import 'profile_screen.dart';

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> with WidgetsBindingObserver {
  int _index = 0;
  int _chatsVisit = 0;
  Timer? _unreadTimer;

  static const _sellIndex = 2;
  static const _chatsIndex = 3;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Бейдж непрочитанных: обновляем раз в 30 с, пока приложение на экране
    _unreadTimer = Timer.periodic(const Duration(seconds: 30), (_) => SessionScope.read(context).refreshChatUnread());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _unreadTimer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) SessionScope.read(context).refreshChatUnread();
  }

  void _onTap(int i) {
    if (i == _sellIndex) {
      requireLogin(context, () async {
        final id = await openListingForm(context);
        if (id != null && mounted) openProduct(context, id);
      });
      return;
    }
    setState(() {
      if (i == _chatsIndex) _chatsVisit++; // список диалогов перечитываем при каждом открытии вкладки
      _index = i;
    });
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    // Ключи по пользователю: при входе/выходе вкладки создаются заново
    final uid = session.user?.id;
    final unread = session.chatUnread;

    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: [
          const CatalogScreen(),
          FavoritesScreen(key: ValueKey('fav-$uid')),
          const SizedBox.shrink(),
          ChatsScreen(key: ValueKey('chats-$uid-$_chatsVisit')),
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
              isLabelVisible: unread > 0,
              label: Text('$unread'),
              child: const Icon(Icons.chat_bubble_outline_rounded),
            ),
            selectedIcon: Badge(
              isLabelVisible: unread > 0,
              label: Text('$unread'),
              child: const Icon(Icons.chat_bubble_rounded),
            ),
            label: 'Сообщения',
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
              Icon(icon, size: 56, color: context.pal.faint2),
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
