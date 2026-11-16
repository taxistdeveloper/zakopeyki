import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../extra_models.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'wallet_screen.dart';

/// Бизнес-аккаунт: заявка на статус ИП/ТОО, бизнес-пакет, доп. опции, использование лимитов.
class BusinessScreen extends StatefulWidget {
  const BusinessScreen({super.key});

  @override
  State<BusinessScreen> createState() => _BusinessScreenState();
}

class _BusinessScreenState extends State<BusinessScreen> {
  BizInfo? _b;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final b = await SessionScope.read(context).api.business();
      if (mounted) setState(() => _b = b);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _buy(BizPackage p) async {
    final b = _b!;
    if (b.walletBalance < p.price) {
      final go = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Недостаточно средств'),
          content: Text('Нужно ${formatMoney(p.price)}, на кошельке ${formatMoney(b.walletBalance)}.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Пополнить кошелёк')),
          ],
        ),
      );
      if (go == true && mounted) {
        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const WalletScreen()));
        _load();
      }
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(p.name),
        content: Text('С кошелька будет списано ${formatMoney(p.price)}'
            '${p.durationDays > 0 ? ' за ${p.durationDays} дн.' : ''}.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Оплатить')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final key = 'bp-app-${List.generate(8, (_) => Random.secure().nextInt(256).toRadixString(16).padLeft(2, '0')).join()}';
    try {
      final msg = await SessionScope.read(context).api.buyBusinessPackage(p.id, key);
      if (mounted) showSnack(context, msg);
      _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = _b;
    return Scaffold(
      appBar: AppBar(title: const Text('Бизнес-аккаунт')),
      body: b == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.only(top: 12, bottom: 24),
                children: [
                  if (!b.isBusiness) _upgradeCard(b),
                  if (b.subscriptionName != null)
                    SectionCard(
                      title: 'Ваш пакет',
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(b.subscriptionName!, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                        if (b.subscriptionEnds != null)
                          Text('Действует до ${formatDate(b.subscriptionEnds)}', style: TextStyle(color: context.pal.muted)),
                        const SizedBox(height: 10),
                        for (final (label, used, cap) in b.usage)
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 4),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(children: [
                                Expanded(child: Text(label, style: const TextStyle(fontSize: 13.5))),
                                Text(cap > 0 ? '$used / $cap' : '$used', style: TextStyle(color: context.pal.muted, fontSize: 13)),
                              ]),
                              if (cap > 0)
                                Padding(
                                  padding: const EdgeInsets.only(top: 4),
                                  child: LinearProgressIndicator(
                                    value: (used / cap).clamp(0, 1).toDouble(),
                                    minHeight: 6,
                                    borderRadius: BorderRadius.circular(3),
                                  ),
                                ),
                            ]),
                          ),
                      ]),
                    ),
                  if (!b.salesEnabled)
                    const SectionCard(child: Text('Продажа бизнес-пакетов временно приостановлена.')),
                  for (final p in b.plans) _packageCard(p, b, highlight: true),
                  if (b.addons.isNotEmpty)
                    const Padding(
                      padding: EdgeInsets.fromLTRB(16, 8, 16, 8),
                      child: Text('Дополнительные опции', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                    ),
                  for (final p in b.addons) _packageCard(p, b),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
                    child: Text('Оплата с кошелька · баланс ${formatMoney(b.walletBalance)}. '
                        'Импорт каталога, API-ключи и AI-инструменты настраиваются на сайте.',
                        style: TextStyle(color: context.pal.muted, fontSize: 12.5)),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _upgradeCard(BizInfo b) {
    final pending = b.upgradeStatus == 'pending';
    final rejected = b.upgradeStatus == 'rejected';
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(colors: [kBrand, kBrandDark]),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Icon(Icons.verified_rounded, color: Colors.white, size: 30),
        const SizedBox(height: 8),
        Text(pending ? 'Заявка на проверке' : 'Станьте бизнес-продавцом',
            style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(
          pending
              ? 'Проверим документы и сообщим в уведомлениях.'
              : rejected
                  ? 'Прошлая заявка отклонена${b.upgradeNote != null ? ': ${b.upgradeNote}' : ''}. Можно подать снова.'
                  : 'Бейдж «Проверенный бизнес», бизнес-пакет, витрина и расширенные лимиты для ИП и ТОО.',
          style: const TextStyle(color: Colors.white70),
        ),
        if (!pending) ...[
          const SizedBox(height: 14),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: kBrand),
            onPressed: () async {
              final ok = await Navigator.of(context).push<bool>(
                MaterialPageRoute(fullscreenDialog: true, builder: (_) => const _UpgradeScreen()),
              );
              if (ok == true) _load();
            },
            child: const Text('Подать заявку'),
          ),
        ],
      ]),
    );
  }

  Widget _packageCard(BizPackage p, BizInfo b, {bool highlight = false}) {
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.pal.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: highlight ? kBrand : context.pal.line, width: highlight ? 1.5 : 1),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
          Text(formatMoney(p.price), style: const TextStyle(fontWeight: FontWeight.w800, color: kBrand, fontSize: 16)),
        ]),
        if (p.durationDays > 0)
          Text('на ${p.durationDays} дн.', style: TextStyle(color: context.pal.muted2, fontSize: 12.5)),
        const SizedBox(height: 8),
        for (final x in p.benefits)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Icon(Icons.check_rounded, size: 18, color: Color(0xFF16A34A)),
              const SizedBox(width: 6),
              Expanded(child: Text(x, style: const TextStyle(fontSize: 13.5))),
            ]),
          ),
        const SizedBox(height: 10),
        (highlight ? FilledButton.new : OutlinedButton.new)(
          onPressed: b.isBusiness && b.salesEnabled ? () => _buy(p) : null,
          child: Text(b.isBusiness ? 'Подключить' : 'Доступно бизнес-аккаунтам'),
        ),
      ]),
    );
  }
}

class _UpgradeScreen extends StatefulWidget {
  const _UpgradeScreen();

  @override
  State<_UpgradeScreen> createState() => _UpgradeScreenState();
}

class _UpgradeScreenState extends State<_UpgradeScreen> {
  String _entity = 'ip';
  final _name = TextEditingController();
  final _bin = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  final _docs = <XFile>[];
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final c in [_name, _bin, _phone, _address]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (_docs.isEmpty) {
      setState(() => _error = 'Приложите фото или скан документов');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final msg = await SessionScope.read(context).api.businessUpgrade({
        'entity_type': _entity,
        'business_name': _name.text.trim(),
        'bin': _bin.text.trim(),
        'phone': _phone.text.trim(),
        'address': _address.text.trim(),
      }, _docs.map((d) => d.path).toList());
      if (!mounted) return;
      showSnack(context, msg);
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Заявка на бизнес-аккаунт')),
      body: ListView(
        padding: const EdgeInsets.only(top: 12),
        children: [
          SectionCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              SegmentedButton<String>(
                segments: const [ButtonSegment(value: 'ip', label: Text('ИП')), ButtonSegment(value: 'too', label: Text('ТОО'))],
                selected: {_entity},
                showSelectedIcon: false,
                onSelectionChanged: (s) => setState(() => _entity = s.first),
              ),
              const SizedBox(height: 12),
              TextField(controller: _name, decoration: const InputDecoration(labelText: 'Название')),
              const SizedBox(height: 12),
              TextField(
                controller: _bin,
                keyboardType: TextInputType.number,
                maxLength: 12,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: InputDecoration(labelText: _entity == 'ip' ? 'ИИН / БИН' : 'БИН', counterText: ''),
              ),
              const SizedBox(height: 12),
              TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Телефон')),
              const SizedBox(height: 12),
              TextField(controller: _address, decoration: const InputDecoration(labelText: 'Юридический адрес')),
            ]),
          ),
          SectionCard(
            title: 'Документы',
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Свидетельство о регистрации или справка. Фото или скан, до 5 файлов.',
                  style: TextStyle(color: context.pal.muted, fontSize: 13)),
              const SizedBox(height: 8),
              for (final d in _docs)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.description_outlined),
                  title: Text(d.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                  trailing: IconButton(onPressed: () => setState(() => _docs.remove(d)), icon: const Icon(Icons.close_rounded)),
                ),
              if (_docs.length < 5)
                OutlinedButton.icon(
                  onPressed: () async {
                    final files = await ImagePicker().pickMultiImage(maxWidth: 2000, imageQuality: 85);
                    if (files.isNotEmpty) setState(() => _docs.addAll(files.take(5 - _docs.length)));
                  },
                  icon: const Icon(Icons.attach_file_rounded),
                  label: const Text('Добавить'),
                ),
            ]),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
              child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ),
        ],
      ),
      bottomNavigationBar: BottomActionBar(
        child: FilledButton(onPressed: _busy ? null : _submit, child: busyLabel(_busy, 'Отправить заявку')),
      ),
    );
  }
}
