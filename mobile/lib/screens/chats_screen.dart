import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'chat_screen.dart';
import 'home_shell.dart';

/// Список диалогов (вкладка «Сообщения»).
class ChatsScreen extends StatefulWidget {
  const ChatsScreen({super.key});

  @override
  State<ChatsScreen> createState() => _ChatsScreenState();
}

class _ChatsScreenState extends State<ChatsScreen> {
  List<ChatSummary>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final session = SessionScope.read(context);
    if (!session.loggedIn) return;
    setState(() => _error = null);
    try {
      final (items, unread) = await session.api.chats();
      if (!mounted) return;
      session.setChatUnread(unread);
      setState(() => _items = items);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _open(ChatSummary c) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ChatScreen(chatId: c.id)));
    if (mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    Widget body;
    if (!session.loggedIn) {
      body = const LoginPrompt(
        icon: Icons.chat_bubble_outline_rounded,
        text: 'Войдите, чтобы переписываться с продавцами и покупателями',
      );
    } else if (_items == null) {
      body = _error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    } else if (_items!.isEmpty) {
      body = RefreshIndicator(
        onRefresh: _load,
        child: ListView(children: const [
          SizedBox(height: 120),
          EmptyView(
            icon: Icons.chat_bubble_outline_rounded,
            title: 'Сообщений пока нет',
            text: 'Нажмите «Написать» в объявлении, чтобы задать вопрос продавцу',
          ),
        ]),
      );
    } else {
      body = RefreshIndicator(
        onRefresh: _load,
        child: ListView.separated(
          itemCount: _items!.length,
          separatorBuilder: (_, _) => const Divider(height: 1, indent: 84),
          itemBuilder: (_, i) => _ChatTile(chat: _items![i], onTap: () => _open(_items![i])),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Сообщения')),
      backgroundColor: context.pal.surface,
      body: body,
    );
  }
}

class _ChatTile extends StatelessWidget {
  const _ChatTile({required this.chat, required this.onTap});

  final ChatSummary chat;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = chat;
    final unread = c.unread > 0;
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Row(
          children: [
            SizedBox(
              width: 56,
              height: 56,
              child: Stack(
                children: [
                  PeerAvatar(peer: c.peer, radius: 26),
                  if (c.product?.image != null)
                    Positioned(
                      right: 0,
                      bottom: 0,
                      child: Container(
                        decoration: BoxDecoration(
                          border: Border.all(color: context.pal.surface, width: 2),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: ProductThumb(c.product!.image, size: 24),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(c.peer.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w600, fontSize: 15.5)),
                      ),
                      Text(chatTime(c.lastMessageAt),
                          style: TextStyle(color: unread ? kBrand : context.pal.muted2, fontSize: 12.5)),
                    ],
                  ),
                  if (c.product != null)
                    Text(c.product!.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: kBrand, fontSize: 13)),
                  const SizedBox(height: 2),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          c.lastPreview ?? 'Нет сообщений',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: unread ? context.pal.ink : context.pal.muted,
                            fontWeight: unread ? FontWeight.w600 : FontWeight.w400,
                          ),
                        ),
                      ),
                      if (unread)
                        Container(
                          margin: const EdgeInsets.only(left: 8),
                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                          decoration: BoxDecoration(color: kBrand, borderRadius: BorderRadius.circular(10)),
                          child: Text('${c.unread}',
                              style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class PeerAvatar extends StatelessWidget {
  const PeerAvatar({super.key, required this.peer, this.radius = 20});

  final ChatPeer peer;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return CircleAvatar(
      radius: radius,
      backgroundColor: context.pal.brandSoft2,
      foregroundImage: peer.avatar != null ? NetworkImage(peer.avatar!) : null,
      child: Text(peer.initial, style: TextStyle(color: kBrand, fontWeight: FontWeight.w700, fontSize: radius * 0.8)),
    );
  }
}

/// Открыть диалог по товару / заказу (кнопка «Написать»).
Future<void> openChatFor(BuildContext context, {int? productId, int? orderId}) async {
  final api = SessionScope.read(context).api;
  try {
    final conv = await api.startChat(productId: productId, orderId: orderId);
    if (!context.mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ChatScreen(chatId: conv.id, initial: conv)));
  } on ApiException catch (e) {
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message), behavior: SnackBarBehavior.floating));
    }
  }
}
