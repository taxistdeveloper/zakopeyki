import 'package:flutter/material.dart';

import '../account_models.dart';
import '../api.dart';
import '../main.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'deep_links.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<AppNotification>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    final api = SessionScope.read(context).api;
    try {
      final (items, unread) = await api.notifications();
      if (!mounted) return;
      setState(() => _items = items);
      if (unread > 0) api.notificationsRead().catchError((_) {});
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _clear() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Очистить уведомления?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Очистить')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await SessionScope.read(context).api.notificationsClear();
      if (mounted) setState(() => _items = []);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Уведомления'),
        actions: [
          if (items != null && items.isNotEmpty)
            IconButton(tooltip: 'Очистить', onPressed: _clear, icon: const Icon(Icons.delete_sweep_outlined)),
        ],
      ),
      backgroundColor: context.pal.surface,
      body: items == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : items.isEmpty
              ? const EmptyView(icon: Icons.notifications_none_rounded, title: 'Уведомлений нет')
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.separated(
                    itemCount: items.length,
                    separatorBuilder: (_, _) => const Divider(height: 1, indent: 72),
                    itemBuilder: (_, i) {
                      final n = items[i];
                      return ListTile(
                        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                        leading: CircleAvatar(
                          backgroundColor: n.read ? context.pal.subtle : context.pal.brandSoft2,
                          child: Icon(_icon(n), color: n.read ? context.pal.faint : kBrand, size: 20),
                        ),
                        title: Text(n.message,
                            style: TextStyle(fontWeight: n.read ? FontWeight.w400 : FontWeight.w600, fontSize: 14.5)),
                        subtitle: Text(formatDate(n.createdAt, withTime: true),
                            style: TextStyle(fontSize: 12, color: context.pal.muted2)),
                        trailing: n.link != null ? Icon(Icons.chevron_right_rounded, color: context.pal.faint2) : null,
                        onTap: n.link == null ? null : () => openSiteLink(context, n.link!),
                      );
                    },
                  ),
                ),
    );
  }

  static IconData _icon(AppNotification n) {
    final l = n.link ?? '';
    if (l.contains('/chat')) return Icons.chat_bubble_outline_rounded;
    if (l.contains('/order')) return Icons.receipt_long_outlined;
    if (l.contains('/wallet')) return Icons.account_balance_wallet_outlined;
    if (l.contains('/support')) return Icons.support_agent_rounded;
    if (l.contains('/product')) return Icons.sell_outlined;
    return Icons.notifications_none_rounded;
  }
}
