import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'chats_screen.dart';
import 'order_screen.dart';

/// Переписка. Новые сообщения подтягиваются опросом, пока экран открыт и приложение активно.
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required this.chatId, this.initial});

  final int chatId;
  final ChatConversation? initial;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> with WidgetsBindingObserver {
  static const _pollEvery = Duration(seconds: 4);

  ChatConversation? _conv;
  final _messages = <ChatMessage>[];
  bool _loaded = false;
  String? _error;
  Timer? _timer;
  bool _polling = false;
  int _tempId = -1;

  final _input = TextEditingController();
  final _scroll = ScrollController();

  int get _lastId => _messages.where((m) => !m.pending).fold(0, (a, m) => m.id > a ? m.id : a);

  @override
  void initState() {
    super.initState();
    _conv = widget.initial;
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _poll();
      _startTimer();
    } else if (state == AppLifecycleState.paused) {
      _timer?.cancel();
    }
  }

  void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(_pollEvery, (_) => _poll());
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final t = await SessionScope.read(context).api.chat(widget.chatId);
      if (!mounted) return;
      SessionScope.read(context).setChatUnread(t.unreadTotal);
      setState(() {
        _conv = t.conversation ?? _conv;
        _messages
          ..clear()
          ..addAll(t.messages);
        _loaded = true;
      });
      _startTimer();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _poll() async {
    if (!_loaded || _polling || !mounted) return;
    _polling = true;
    try {
      final t = await SessionScope.read(context).api.chat(widget.chatId, after: _lastId);
      if (!mounted) return;
      SessionScope.read(context).setChatUnread(t.unreadTotal);
      if (t.messages.isNotEmpty) {
        setState(() {
          final known = _messages.map((m) => m.id).toSet();
          _messages.addAll(t.messages.where((m) => !known.contains(m.id)));
        });
      }
    } catch (_) {
      // сеть пропала — попробуем на следующем тике
    } finally {
      _polling = false;
    }
  }

  Future<void> _send() async {
    final text = _input.text.trim();
    if (text.isEmpty) return;
    final temp = ChatMessage(_tempId--, true, text, false, DateTime.now().toIso8601String(), pending: true);
    setState(() {
      _messages.add(temp);
      _input.clear();
    });
    try {
      final sent = await SessionScope.read(context).api.sendMessage(widget.chatId, text);
      if (!mounted) return;
      setState(() {
        final i = _messages.indexOf(temp);
        if (_messages.any((m) => m.id == sent.id)) {
          _messages.remove(temp); // уже пришло опросом
        } else if (i >= 0) {
          _messages[i] = sent;
        }
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _messages.remove(temp));
      _input.text = text;
      showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final conv = _conv;
    return Scaffold(
      backgroundColor: const Color(0xFFF1F5F9),
      appBar: AppBar(
        titleSpacing: 0,
        title: conv == null
            ? const Text('Сообщения')
            : Row(
                children: [
                  PeerAvatar(peer: conv.peer, radius: 18),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(conv.peer.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                  ),
                ],
              ),
      ),
      body: Column(
        children: [
          if (conv?.product != null) _productBar(conv!),
          Expanded(child: _list()),
          _composer(),
        ],
      ),
    );
  }

  Widget _productBar(ChatConversation conv) {
    final p = conv.product!;
    return Material(
      color: Colors.white,
      child: InkWell(
        onTap: () => conv.orderId != null
            ? Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(id: conv.orderId!)))
            : openProduct(context, p.id),
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 8, 12, 8),
          decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: Color(0xFFE2E8F0)))),
          child: Row(
            children: [
              ProductThumb(p.image, size: 40),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(p.title, maxLines: 1, overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(conv.orderId != null ? 'Заказ №${conv.orderId}' : 'Объявление',
                        style: const TextStyle(color: Colors.black54, fontSize: 12.5)),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: Colors.black38),
            ],
          ),
        ),
      ),
    );
  }

  Widget _list() {
    if (!_loaded) {
      return _error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    }
    if (_messages.isEmpty) {
      return const EmptyView(
        icon: Icons.waving_hand_outlined,
        title: 'Начните диалог',
        text: 'Спросите о состоянии, договоритесь о встрече или доставке',
      );
    }
    final items = _messages.reversed.toList();
    return ListView.builder(
      controller: _scroll,
      reverse: true,
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
      itemCount: items.length,
      itemBuilder: (_, i) {
        final m = items[i];
        final older = i + 1 < items.length ? items[i + 1] : null;
        final showDate = older == null || _day(older.createdAt) != _day(m.createdAt);
        return Column(
          children: [
            if (showDate) _dateChip(m.createdAt),
            _Bubble(message: m),
          ],
        );
      },
    );
  }

  static String _day(String? iso) {
    final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
    return d == null ? '' : '${d.year}-${d.month}-${d.day}';
  }

  Widget _dateChip(String? iso) {
    final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
    if (d == null) return const SizedBox.shrink();
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final diff = today.difference(DateTime(d.year, d.month, d.day)).inDays;
    final label = diff == 0 ? 'Сегодня' : (diff == 1 ? 'Вчера' : formatDate(iso));
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
        decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.06), borderRadius: BorderRadius.circular(10)),
        child: Text(label, style: const TextStyle(fontSize: 12, color: Colors.black54)),
      ),
    );
  }

  Widget _composer() {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
      child: SafeArea(
        top: false,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: TextField(
                controller: _input,
                minLines: 1,
                maxLines: 5,
                maxLength: 2000,
                textCapitalization: TextCapitalization.sentences,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  hintText: 'Сообщение',
                  counterText: '',
                  fillColor: const Color(0xFFF1F5F9),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(22), borderSide: BorderSide.none),
                  enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(22), borderSide: BorderSide.none),
                  focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(22), borderSide: BorderSide.none),
                ),
              ),
            ),
            const SizedBox(width: 6),
            IconButton.filled(
              onPressed: _loaded && _input.text.trim().isNotEmpty ? _send : null,
              style: IconButton.styleFrom(minimumSize: const Size(44, 44), backgroundColor: kBrand),
              icon: const Icon(Icons.send_rounded, size: 20),
            ),
          ],
        ),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message});

  final ChatMessage message;

  @override
  Widget build(BuildContext context) {
    final m = message;
    final d = m.createdAt == null ? null : DateTime.tryParse(m.createdAt!)?.toLocal();
    final time = d == null ? '' : '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
    final fg = m.mine ? Colors.white : const Color(0xFF0F172A);
    final meta = m.mine ? Colors.white70 : Colors.black45;
    return Align(
      alignment: m.mine ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
        child: Container(
          margin: const EdgeInsets.symmetric(vertical: 2),
          padding: const EdgeInsets.fromLTRB(12, 8, 10, 6),
          decoration: BoxDecoration(
            color: m.mine ? kBrand : Colors.white,
            borderRadius: BorderRadius.only(
              topLeft: const Radius.circular(18),
              topRight: const Radius.circular(18),
              bottomLeft: Radius.circular(m.mine ? 18 : 4),
              bottomRight: Radius.circular(m.mine ? 4 : 18),
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              SelectableText(m.body, style: TextStyle(color: fg, fontSize: 15.5, height: 1.3)),
              const SizedBox(height: 2),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(time, style: TextStyle(color: meta, fontSize: 11)),
                  if (m.mine) ...[
                    const SizedBox(width: 3),
                    Icon(
                      m.pending ? Icons.schedule_rounded : (m.read ? Icons.done_all_rounded : Icons.done_rounded),
                      size: 14,
                      color: meta,
                    ),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
