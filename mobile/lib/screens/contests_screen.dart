import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../api.dart';
import '../extra_models.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'seller_screen.dart';

/// Конкурсы и розыгрыши: идут / скоро / итоги / мои.
class ContestsScreen extends StatelessWidget {
  const ContestsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 4,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Конкурсы и розыгрыши'),
          bottom: const TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [Tab(text: 'Идут'), Tab(text: 'Скоро'), Tab(text: 'Итоги'), Tab(text: 'Мои')],
          ),
        ),
        body: const TabBarView(children: [
          _ContestList(status: 'active'),
          _ContestList(status: 'upcoming'),
          _ContestList(status: 'finished'),
          _MyContests(),
        ]),
      ),
    );
  }
}

class _ContestList extends StatefulWidget {
  const _ContestList({required this.status});

  final String status;

  @override
  State<_ContestList> createState() => _ContestListState();
}

class _ContestListState extends State<_ContestList> with AutomaticKeepAliveClientMixin {
  List<ContestCard>? _items;
  bool _enabled = true;
  String? _error;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final (enabled, items, _) = await SessionScope.read(context).api.contests(status: widget.status);
      if (mounted) {
        setState(() {
          _enabled = enabled;
          _items = items;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    final items = _items;
    if (items == null) {
      return _error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    }
    if (!_enabled || items.isEmpty) {
      return RefreshIndicator(
        onRefresh: _load,
        child: ListView(children: [
          const SizedBox(height: 100),
          EmptyView(
            icon: Icons.emoji_events_outlined,
            title: !_enabled ? 'Конкурсы скоро появятся' : 'Пока пусто',
            text: !_enabled ? 'Раздел запускается вместе с площадкой' : null,
          ),
        ]),
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.only(top: 12, bottom: 24),
        itemCount: items.length,
        itemBuilder: (_, i) => ContestTile(contest: items[i], onReturn: _load),
      ),
    );
  }
}

class ContestTile extends StatelessWidget {
  const ContestTile({super.key, required this.contest, this.onReturn});

  final ContestCard contest;
  final VoidCallback? onReturn;

  @override
  Widget build(BuildContext context) {
    final c = contest;
    return GestureDetector(
      onTap: () async {
        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ContestScreen(slug: c.slug)));
        onReturn?.call();
      },
      child: Container(
        margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        decoration: BoxDecoration(
          color: context.pal.surface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: context.pal.line),
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          AspectRatio(
            aspectRatio: 2.2,
            child: c.cover != null
                ? CachedNetworkImage(imageUrl: c.cover!, fit: BoxFit.cover, errorWidget: (_, _, _) => _coverStub())
                : _coverStub(),
          ),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                StatusPill(c.statusLabel, color: c.status == 'active' ? const Color(0xFF16A34A) : kBrand),
                const SizedBox(width: 6),
                StatusPill(c.mechanicLabel, color: const Color(0xFF7C3AED)),
              ]),
              const SizedBox(height: 6),
              Text(c.title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
              if (c.prize != null) ...[
                const SizedBox(height: 2),
                Text('🎁 ${c.prize}', maxLines: 2, overflow: TextOverflow.ellipsis),
              ],
              const SizedBox(height: 6),
              Text(
                [c.organizer, 'участников: ${c.entries}', if (c.endsAt != null) 'до ${formatDate(c.endsAt)}'].join(' · '),
                style: TextStyle(color: context.pal.muted2, fontSize: 12.5),
              ),
            ]),
          ),
        ]),
      ),
    );
  }

  Widget _coverStub() => const DecoratedBox(
        decoration: BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF7C3AED), kBrand])),
        child: Center(child: Icon(Icons.emoji_events_rounded, color: Colors.white70, size: 48)),
      );
}

class _MyContests extends StatefulWidget {
  const _MyContests();

  @override
  State<_MyContests> createState() => _MyContestsState();
}

class _MyContestsState extends State<_MyContests> with AutomaticKeepAliveClientMixin {
  MyContests? _d;
  String? _error;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    if (SessionScope.read(context).loggedIn) _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SessionScope.read(context).api.myContests();
      if (mounted) setState(() => _d = d);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    if (!SessionScope.of(context).loggedIn) {
      return Center(child: FilledButton(onPressed: () => openLogin(context), child: const Text('Войти')));
    }
    final d = _d;
    if (d == null) {
      return _error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    }
    void open(String slug) async {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ContestScreen(slug: slug)));
      _load();
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.only(top: 12, bottom: 24), children: [
        if (d.wins.isNotEmpty)
          SectionCard(
            title: '🏆 Мои выигрыши',
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
            child: Column(children: [
              for (final w in d.wins)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${w['place']} место · ${w['title']}'),
                  subtitle: Text([if (w['prize'] != null) w['prize'].toString(), w['fulfilment_label'].toString()].join(' · ')),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => open(w['slug'].toString()),
                ),
            ]),
          ),
        SectionCard(
          title: 'Участвую',
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
          child: d.participations.isEmpty
              ? Padding(padding: EdgeInsets.only(bottom: 12), child: Text('Вы пока ни в чём не участвуете', style: TextStyle(color: context.pal.muted)))
              : Column(children: [
                  for (final p in d.participations)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(p['title'].toString()),
                      subtitle: Text('${p['contest_status_label']} · ${p['entry_status_label']}'),
                      trailing: const Icon(Icons.chevron_right_rounded),
                      onTap: () => open(p['slug'].toString()),
                    ),
                ]),
        ),
        if (d.organized.isNotEmpty) ...[
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Text('Мои акции', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          ),
          for (final c in d.organized) ContestTile(contest: c, onReturn: _load),
        ],
      ]),
    );
  }
}

// =============================================================== карточка конкурса

class ContestScreen extends StatefulWidget {
  const ContestScreen({super.key, required this.slug});

  final String slug;

  @override
  State<ContestScreen> createState() => _ContestScreenState();
}

class _ContestScreenState extends State<ContestScreen> {
  ContestDetail? _c;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final c = await SessionScope.read(context).api.contest(widget.slug);
      if (mounted) setState(() => _c = c);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _act(String action, [Map<String, dynamic>? body]) async {
    setState(() => _busy = true);
    try {
      final msg = await SessionScope.read(context).api.contestAction(widget.slug, action, body);
      if (mounted) showSnack(context, msg);
      await _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _join() async {
    await requireLogin(context, () async {
      final c = _c!;
      if (!c.requiresSubmission) {
        await _act('join');
        return;
      }
      final text = await _askText('Ваша работа', 'Опишите или вставьте ссылку на работу', maxLength: 2000);
      if (text != null) await _act('join', {'submission': text});
    });
  }

  Future<String?> _askText(String title, String hint, {int maxLength = 1000}) {
    final ctrl = TextEditingController();
    return showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(title, style: Theme.of(ctx).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          TextField(controller: ctrl, autofocus: true, minLines: 3, maxLines: 8, maxLength: maxLength, decoration: InputDecoration(hintText: hint)),
          const SizedBox(height: 12),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('Отправить')),
        ]),
      ),
    ).whenComplete(ctrl.dispose);
  }

  Future<void> _report() async {
    final c = _c!;
    String? reason = c.reportReasons.firstOrNull?.$1;
    final details = TextEditingController();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text('Пожаловаться на акцию', style: Theme.of(ctx).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
              RadioGroup<String>(
                groupValue: reason,
                onChanged: (v) => setLocal(() => reason = v),
                child: Column(children: [
                  for (final (code, label) in c.reportReasons)
                    RadioListTile<String>(value: code, contentPadding: EdgeInsets.zero, title: Text(label)),
                ]),
              ),
              TextField(controller: details, minLines: 2, maxLines: 5, decoration: const InputDecoration(labelText: 'Подробности')),
              const SizedBox(height: 12),
              FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Отправить жалобу')),
            ]),
          ),
        ),
      ),
    );
    if (ok == true && reason != null) await _act('report', {'reason': reason, 'details': details.text.trim()});
    details.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = _c;
    return Scaffold(
      appBar: AppBar(
        title: Text(c?.card.title ?? 'Акция', maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          if (c != null)
            IconButton(
              tooltip: 'Поделиться',
              onPressed: () => SharePlus.instance.share(ShareParams(text: '${c.card.title}\n${c.personalUrl ?? c.publicUrl}')),
              icon: const Icon(Icons.share_outlined),
            ),
          if (c != null && !c.hasOpenReport && SessionScope.of(context).loggedIn)
            IconButton(tooltip: 'Пожаловаться', onPressed: _report, icon: const Icon(Icons.flag_outlined)),
        ],
      ),
      body: c == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
                if (c.card.cover != null)
                  AspectRatio(
                    aspectRatio: 2,
                    child: CachedNetworkImage(imageUrl: c.card.cover!, fit: BoxFit.cover, errorWidget: (_, _, _) => const SizedBox()),
                  ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Wrap(spacing: 6, children: [
                      StatusPill(c.card.statusLabel, color: c.card.status == 'active' ? const Color(0xFF16A34A) : kBrand),
                      StatusPill(c.card.mechanicLabel, color: const Color(0xFF7C3AED)),
                    ]),
                    const SizedBox(height: 8),
                    Text(c.card.title, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 6),
                    InkWell(
                      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => SellerScreen(id: c.card.organizerId))),
                      child: Text('Организатор: ${c.card.organizer}', style: const TextStyle(color: kBrand, fontWeight: FontWeight.w600)),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      [
                        if (c.card.startsAt != null) 'с ${formatDate(c.card.startsAt, withTime: true)}',
                        if (c.card.endsAt != null) 'до ${formatDate(c.card.endsAt, withTime: true)}',
                        'участников: ${c.card.entries}',
                      ].join(' · '),
                      style: TextStyle(color: context.pal.muted, fontSize: 13),
                    ),
                  ]),
                ),
                if (c.myEntryStatus != null)
                  SectionCard(
                    child: Row(children: [
                      const Icon(Icons.how_to_reg_rounded, color: Color(0xFF16A34A)),
                      const SizedBox(width: 10),
                      Expanded(child: Text('Вы ${c.myEntryStatus}')),
                    ]),
                  ),
                if (c.prizes.isNotEmpty || c.card.prize != null)
                  SectionCard(
                    title: 'Призы',
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      if (c.prizes.isEmpty && c.card.prize != null) Text(c.card.prize!),
                      for (final (place, title) in c.prizes)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 3),
                          child: Text('$place. $title'),
                        ),
                    ]),
                  ),
                if (c.winners.isNotEmpty)
                  SectionCard(
                    title: 'Победители',
                    child: Column(children: [
                      for (final w in c.winners)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                            Text('${w.place}. ${w.name}${w.isMe ? ' (вы)' : ''}${w.prize != null ? ' — ${w.prize}' : ''}',
                                style: TextStyle(fontWeight: w.isMe ? FontWeight.w800 : FontWeight.w500)),
                            if (w.fulfilmentLabel.isNotEmpty)
                              Text(w.fulfilmentLabel, style: TextStyle(color: context.pal.muted, fontSize: 12.5)),
                            if (w.isMe && w.fulfilment == 'handed_over')
                              Row(children: [
                                Expanded(
                                  child: FilledButton(
                                    onPressed: _busy ? null : () => _act('prize/${w.id}/confirm'),
                                    child: const Text('Приз получен'),
                                  ),
                                ),
                                const SizedBox(width: 8),
                                TextButton(
                                  onPressed: _busy
                                      ? null
                                      : () async {
                                          final note = await _askText('Приз не получен', 'Что случилось?');
                                          if (note != null) await _act('prize/${w.id}/dispute', {'note': note});
                                        },
                                  child: const Text('Не получен'),
                                ),
                              ]),
                          ]),
                        ),
                    ]),
                  ),
                if (c.description.isNotEmpty) SectionCard(title: 'Описание', child: SelectableText(c.description)),
                if (c.conditions.isNotEmpty)
                  SectionCard(
                    title: 'Условия участия',
                    child: Column(children: [
                      for (final cond in c.conditions)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 3),
                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Icon(
                              cond.met == null
                                  ? Icons.radio_button_unchecked_rounded
                                  : (cond.met! ? Icons.check_circle_rounded : Icons.cancel_rounded),
                              size: 18,
                              color: cond.met == null ? context.pal.faint : (cond.met! ? const Color(0xFF16A34A) : const Color(0xFFDC2626)),
                            ),
                            const SizedBox(width: 8),
                            Expanded(child: Text(cond.label)),
                          ]),
                        ),
                    ]),
                  ),
                if (c.mechanicHint.isNotEmpty) SectionCard(title: c.card.mechanicLabel, child: Text(c.mechanicHint)),
                if (c.criteriaText != null) SectionCard(title: 'Критерии', child: SelectableText(c.criteriaText!)),
                if (c.rulesText.isNotEmpty) SectionCard(title: 'Правила', child: SelectableText(c.rulesText)),
              ]),
            ),
      bottomNavigationBar: c == null || (!c.canJoin && !c.canWithdraw)
          ? null
          : BottomActionBar(
              child: c.canJoin
                  ? FilledButton(onPressed: _busy ? null : _join, child: busyLabel(_busy, c.requiresSubmission ? 'Отправить работу' : 'Участвовать'))
                  : OutlinedButton(
                      onPressed: _busy
                          ? null
                          : () async {
                              final ok = await showDialog<bool>(
                                context: context,
                                builder: (ctx) => AlertDialog(
                                  title: const Text('Выйти из акции?'),
                                  actions: [
                                    TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Остаться')),
                                    FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Выйти')),
                                  ],
                                ),
                              );
                              if (ok == true) await _act('withdraw');
                            },
                      child: const Text('Выйти из акции'),
                    ),
            ),
    );
  }
}
