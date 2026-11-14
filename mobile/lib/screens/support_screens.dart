import 'package:flutter/material.dart';

import '../account_models.dart';
import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Мои обращения в поддержку.
class SupportListScreen extends StatefulWidget {
  const SupportListScreen({super.key, this.newTicketCategory});

  /// Если задано — после загрузки сразу открыть форму обращения с этой темой.
  final String? newTicketCategory;

  @override
  State<SupportListScreen> createState() => _SupportListScreenState();
}

class _SupportListScreenState extends State<SupportListScreen> {
  List<SupportTicket>? _items;
  List<SupportCategory> _categories = const [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final (items, cats) = await SessionScope.read(context).api.supportTickets();
      if (mounted) {
        setState(() {
          _items = items;
          _categories = cats;
        });
        final preset = widget.newTicketCategory;
        if (preset != null && !_presetShown) {
          _presetShown = true;
          _create(category: preset);
        }
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  bool _presetShown = false;

  Future<void> _create({String category = 'general'}) async {
    final ticket = await Navigator.of(context).push<SupportTicket>(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => _NewTicketScreen(categories: _categories, initialCategory: category),
    ));
    if (ticket == null || !mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => SupportTicketScreen(id: ticket.id)));
    if (mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    return Scaffold(
      appBar: AppBar(title: const Text('Поддержка')),
      floatingActionButton: items == null
          ? null
          : FloatingActionButton.extended(
              onPressed: _create,
              icon: const Icon(Icons.edit_outlined),
              label: const Text('Новое обращение'),
            ),
      body: items == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : items.isEmpty
              ? const EmptyView(
                  icon: Icons.support_agent_rounded,
                  title: 'Мы на связи',
                  text: 'Опишите вопрос или проблему — ответим здесь и пришлём уведомление',
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    padding: const EdgeInsets.only(top: 12, bottom: 88),
                    itemCount: items.length,
                    itemBuilder: (_, i) {
                      final t = items[i];
                      return GestureDetector(
                        onTap: () async {
                          await Navigator.of(context).push(MaterialPageRoute(builder: (_) => SupportTicketScreen(id: t.id)));
                          if (mounted) _load();
                        },
                        child: SectionCard(
                          padding: const EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Expanded(
                                    child: Text(t.subject,
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: TextStyle(
                                            fontWeight: t.unread > 0 ? FontWeight.w800 : FontWeight.w600)),
                                  ),
                                  if (t.unread > 0) Badge(label: Text('${t.unread}')),
                                ],
                              ),
                              const SizedBox(height: 2),
                              Text('${t.number} · ${t.categoryLabel}',
                                  style: const TextStyle(color: Colors.black45, fontSize: 12.5)),
                              if (t.lastPreview != null) ...[
                                const SizedBox(height: 6),
                                Text(t.lastPreview!.replaceAll('\n', ' '),
                                    maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.black54)),
                              ],
                              const SizedBox(height: 8),
                              Row(
                                children: [
                                  StatusPill(t.statusLabel, color: _statusColor(t.status)),
                                  const Spacer(),
                                  Text(chatTime(t.lastMessageAt), style: const TextStyle(color: Colors.black45, fontSize: 12)),
                                ],
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}

Color _statusColor(String s) => switch (s) {
      'answered' => const Color(0xFF16A34A),
      'closed' => Colors.black45,
      _ => kBrand,
    };

class _NewTicketScreen extends StatefulWidget {
  const _NewTicketScreen({required this.categories, this.initialCategory = 'general'});

  final List<SupportCategory> categories;
  final String initialCategory;

  @override
  State<_NewTicketScreen> createState() => _NewTicketScreenState();
}

class _NewTicketScreenState extends State<_NewTicketScreen> {
  final _subject = TextEditingController();
  final _body = TextEditingController();
  late String _category = widget.initialCategory;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_subject.text.trim().isEmpty || _body.text.trim().isEmpty) {
      setState(() => _error = 'Заполните тему и описание');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final t = await SessionScope.read(context).api.createSupportTicket(_subject.text.trim(), _body.text.trim(), _category);
      if (mounted) Navigator.of(context).pop(t);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Новое обращение')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text('Тема', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final c in widget.categories)
                ChoiceChip(
                  label: Text(c.label),
                  selected: _category == c.code,
                  onSelected: (_) => setState(() => _category = c.code),
                ),
            ],
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _subject,
            maxLength: 200,
            textCapitalization: TextCapitalization.sentences,
            decoration: const InputDecoration(labelText: 'Коротко о вопросе'),
          ),
          const SizedBox(height: 4),
          TextField(
            controller: _body,
            minLines: 5,
            maxLines: 12,
            textCapitalization: TextCapitalization.sentences,
            decoration: const InputDecoration(
              labelText: 'Подробности',
              hintText: 'Номер заказа, что произошло, что ожидали…',
              alignLabelWithHint: true,
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ],
        ],
      ),
      bottomNavigationBar: BottomActionBar(
        child: FilledButton(onPressed: _busy ? null : _submit, child: busyLabel(_busy, 'Отправить')),
      ),
    );
  }
}

/// Переписка с поддержкой по одному обращению.
class SupportTicketScreen extends StatefulWidget {
  const SupportTicketScreen({super.key, required this.id});

  final int id;

  @override
  State<SupportTicketScreen> createState() => _SupportTicketScreenState();
}

class _SupportTicketScreenState extends State<SupportTicketScreen> {
  SupportTicket? _ticket;
  List<SupportMessage> _messages = const [];
  String? _error;
  bool _sending = false;
  final _input = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final (t, m) = await SessionScope.read(context).api.supportTicket(widget.id);
      if (mounted) {
        setState(() {
          _ticket = t;
          _messages = m;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _send() async {
    final text = _input.text.trim();
    if (text.isEmpty) return;
    setState(() => _sending = true);
    try {
      final (t, m) = await SessionScope.read(context).api.replySupport(widget.id, text);
      if (!mounted) return;
      _input.clear();
      setState(() {
        _ticket = t;
        _messages = m;
      });
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = _ticket;
    return Scaffold(
      backgroundColor: const Color(0xFFF1F5F9),
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(t?.subject ?? 'Обращение', maxLines: 1, overflow: TextOverflow.ellipsis),
            if (t != null)
              Text('${t.number} · ${t.statusLabel}', style: const TextStyle(fontSize: 12.5, color: Colors.black54)),
          ],
        ),
      ),
      body: Column(
        children: [
          Expanded(
            child: t == null
                ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView.builder(
                      reverse: true,
                      padding: const EdgeInsets.all(12),
                      itemCount: _messages.length,
                      itemBuilder: (_, i) => _bubble(_messages[_messages.length - 1 - i]),
                    ),
                  ),
          ),
          if (t != null && t.status != 'closed')
            Container(
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
                        textCapitalization: TextCapitalization.sentences,
                        onChanged: (_) => setState(() {}),
                        decoration: const InputDecoration(hintText: 'Ответить'),
                      ),
                    ),
                    const SizedBox(width: 6),
                    IconButton.filled(
                      onPressed: _sending || _input.text.trim().isEmpty ? null : _send,
                      style: IconButton.styleFrom(minimumSize: const Size(44, 44), backgroundColor: kBrand),
                      icon: const Icon(Icons.send_rounded, size: 20),
                    ),
                  ],
                ),
              ),
            )
          else if (t != null)
            Container(
              width: double.infinity,
              color: Colors.white,
              padding: const EdgeInsets.all(16),
              child: const SafeArea(
                top: false,
                child: Text('Обращение закрыто. Если вопрос остался — создайте новое.',
                    textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
              ),
            ),
        ],
      ),
    );
  }

  Widget _bubble(SupportMessage m) {
    final mine = m.from == 'user';
    final label = switch (m.from) { 'admin' => 'Поддержка', 'system' => 'Автоответ', _ => null };
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.8),
        child: Container(
          margin: const EdgeInsets.symmetric(vertical: 3),
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 6),
          decoration: BoxDecoration(
            color: mine ? kBrand : Colors.white,
            borderRadius: BorderRadius.circular(16),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (label != null)
                Text(label, style: const TextStyle(color: kBrand, fontWeight: FontWeight.w700, fontSize: 12.5)),
              SelectableText(m.body, style: TextStyle(color: mine ? Colors.white : const Color(0xFF0F172A), height: 1.3)),
              const SizedBox(height: 2),
              Align(
                alignment: Alignment.centerRight,
                child: Text(formatDate(m.createdAt, withTime: true),
                    style: TextStyle(fontSize: 11, color: mine ? Colors.white70 : Colors.black45)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
