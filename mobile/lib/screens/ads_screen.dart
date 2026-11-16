import 'dart:io';
import 'dart:math';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../extra_models.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'payment_screen.dart';

/// Реклама на площадке: мои кампании, создание, оплата картой, пауза.
class AdsScreen extends StatefulWidget {
  const AdsScreen({super.key});

  @override
  State<AdsScreen> createState() => _AdsScreenState();
}

class _AdsScreenState extends State<AdsScreen> {
  AdsData? _d;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SessionScope.read(context).api.ads();
      if (mounted) setState(() => _d = d);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _create({AdCampaignItem? resubmit}) async {
    final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => _AdFormScreen(data: _d!, resubmit: resubmit),
    ));
    if (ok == true) _load();
  }

  Future<void> _pay(AdCampaignItem c) async {
    final key = 'ad-app-${List.generate(8, (_) => Random.secure().nextInt(256).toRadixString(16).padLeft(2, '0')).join()}';
    try {
      final url = await SessionScope.read(context).api.payAd(c.id, key);
      if (!mounted) return;
      if (url == null) {
        showSnack(context, 'Кампания уже запущена');
      } else {
        final status = await Navigator.of(context).push<String>(
          MaterialPageRoute(fullscreenDialog: true, builder: (_) => PaymentScreen(url: url)),
        );
        if (mounted) showSnack(context, status == 'success' ? 'Оплата прошла — показ скоро начнётся' : 'Оплата не завершена');
      }
      _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  Future<void> _toggle(AdCampaignItem c, bool pause) async {
    try {
      final msg = await SessionScope.read(context).api.adRunning(c.id, pause: pause);
      if (mounted) showSnack(context, msg);
      _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    return Scaffold(
      appBar: AppBar(title: const Text('Реклама')),
      floatingActionButton: d == null || !d.formats.any((f) => f.enabled)
          ? null
          : FloatingActionButton.extended(
              onPressed: _create,
              icon: const Icon(Icons.campaign_rounded),
              label: const Text('Новая кампания'),
            ),
      body: d == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: d.campaigns.isEmpty
                  ? ListView(children: [
                      const SizedBox(height: 80),
                      const EmptyView(
                        icon: Icons.campaign_outlined,
                        title: 'Продвигайте бизнес на площадке',
                        text: 'Баннер на главной, в категории или в карточках товаров. Оплата за показы, сумму считает сервер.',
                      ),
                      const SizedBox(height: 16),
                      for (final f in d.formats)
                        ListTile(
                          leading: Icon(Icons.check_circle_outline_rounded, color: f.enabled ? kBrand : context.pal.faint2),
                          title: Text(f.label),
                          subtitle: Text(f.unit == 'week'
                              ? '${formatMoney(f.unitPrice)} за неделю'
                              : '${formatMoney(f.unitPrice)} за 1 000 показов'),
                        ),
                    ])
                  : ListView(
                      padding: const EdgeInsets.only(top: 12, bottom: 96),
                      children: [for (final c in d.campaigns) _campaign(c)],
                    ),
            ),
    );
  }

  Widget _campaign(AdCampaignItem c) {
    final progress = c.impressionsPaid > 0 ? (c.impressionsUsed / c.impressionsPaid).clamp(0, 1).toDouble() : null;
    return SectionCard(
      padding: const EdgeInsets.all(12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (c.image != null)
          ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: AspectRatio(
              aspectRatio: 3.2,
              child: CachedNetworkImage(imageUrl: c.image!, fit: BoxFit.cover, errorWidget: (_, _, _) => ColoredBox(color: context.pal.subtle)),
            ),
          ),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: Text(c.title, style: const TextStyle(fontWeight: FontWeight.w700))),
          StatusPill(c.statusLabel, color: switch (c.status) {
            'active' => const Color(0xFF16A34A),
            'rejected' || 'failed' => const Color(0xFFDC2626),
            'pending_moderation' || 'approved' || 'pending_payment' => const Color(0xFFF59E0B),
            _ => context.pal.muted2,
          }),
        ]),
        Text('${c.formatLabel} · ${formatMoney(c.amount)}', style: TextStyle(color: context.pal.muted, fontSize: 13)),
        if (progress != null) ...[
          const SizedBox(height: 8),
          LinearProgressIndicator(value: progress, minHeight: 6, borderRadius: BorderRadius.circular(3)),
          const SizedBox(height: 4),
          Text('Показы ${c.impressionsUsed} из ${c.impressionsPaid} · переходы ${c.clicks}',
              style: TextStyle(color: context.pal.muted, fontSize: 12.5)),
        ] else if (c.endsAt != null)
          Text('До ${formatDate(c.endsAt)} · переходы ${c.clicks}', style: TextStyle(color: context.pal.muted, fontSize: 12.5)),
        if (c.rejectReason != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text('Причина отклонения: ${c.rejectReason}', style: const TextStyle(color: Color(0xFFDC2626), fontSize: 13)),
          ),
        if (c.canPay || c.canPause || c.canResume || c.canResubmit) const SizedBox(height: 10),
        if (c.canPay) FilledButton(onPressed: () => _pay(c), child: Text('Оплатить ${formatMoney(c.amount)}')),
        if (c.canPause) OutlinedButton(onPressed: () => _toggle(c, true), child: const Text('Поставить на паузу')),
        if (c.canResume) FilledButton(onPressed: () => _toggle(c, false), child: const Text('Возобновить показ')),
        if (c.canResubmit) OutlinedButton(onPressed: () => _create(resubmit: c), child: const Text('Исправить и отправить снова')),
      ]),
    );
  }
}

class _AdFormScreen extends StatefulWidget {
  const _AdFormScreen({required this.data, this.resubmit});

  final AdsData data;
  final AdCampaignItem? resubmit;

  @override
  State<_AdFormScreen> createState() => _AdFormScreenState();
}

class _AdFormScreenState extends State<_AdFormScreen> {
  late AdFormat _format = widget.data.formats.firstWhere((f) => f.enabled, orElse: () => widget.data.formats.first);
  int _units = 5;
  AdCategory? _category;
  late final _title = TextEditingController(text: widget.resubmit?.title ?? '');
  late final _url = TextEditingController(text: widget.resubmit?.targetUrl ?? 'https://');
  XFile? _image;
  bool _busy = false;
  String? _error;

  bool get _resubmit => widget.resubmit != null;

  @override
  void dispose() {
    _title.dispose();
    _url.dispose();
    super.dispose();
  }

  Future<void> _pickCategory() async {
    final cats = widget.data.categories;
    final c = await showModalBottomSheet<AdCategory>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (ctx) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.7,
        builder: (ctx, scroll) => ListView(controller: scroll, children: [
          for (final c in cats)
            ListTile(
              contentPadding: EdgeInsets.only(left: c.parent == null ? 16 : 36, right: 16),
              title: Text(c.label, style: TextStyle(fontWeight: c.parent == null ? FontWeight.w700 : FontWeight.w400)),
              onTap: () => Navigator.pop(ctx, c),
            ),
        ]),
      ),
    );
    if (c != null) setState(() => _category = c);
  }

  Future<void> _submit() async {
    if (!_resubmit && _image == null) {
      setState(() => _error = 'Добавьте изображение баннера');
      return;
    }
    if (!_resubmit && _format.category == 'required' && _category == null) {
      setState(() => _error = 'Выберите категорию');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final api = SessionScope.read(context).api;
    try {
      final String msg;
      if (_resubmit) {
        msg = await api.resubmitAd(widget.resubmit!.id, {'title': _title.text.trim(), 'target_url': _url.text.trim()}, _image?.path);
      } else {
        msg = await api.createAd({
          'format': _format.code,
          'units': '$_units',
          if (_category != null) 'category': _category!.value,
          'title': _title.text.trim(),
          'target_url': _url.text.trim(),
        }, _image!.path);
      }
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
    final f = _format;
    final week = f.unit == 'week';
    return Scaffold(
      appBar: AppBar(title: Text(_resubmit ? 'Исправить кампанию' : 'Новая кампания')),
      body: ListView(
        padding: const EdgeInsets.only(top: 12, bottom: 24),
        children: [
          if (!_resubmit) ...[
            SectionCard(
              title: 'Формат',
              child: RadioGroup<String>(
                groupValue: _format.code,
                onChanged: (code) {
                  final x = widget.data.formats.firstWhere((f) => f.code == code);
                  setState(() {
                    _format = x;
                    _units = min(_units, x.maxUnits);
                    if (x.category == 'none') _category = null;
                  });
                },
                child: Column(children: [
                  for (final x in widget.data.formats)
                    RadioListTile<String>(
                      value: x.code,
                      enabled: x.enabled,
                      contentPadding: EdgeInsets.zero,
                      title: Text(x.label),
                      subtitle: Text(!x.enabled
                          ? 'Сейчас не принимает заказы'
                          : (x.unit == 'week' ? '${formatMoney(x.unitPrice)} / неделя' : '${formatMoney(x.unitPrice)} / 1 000 показов')),
                    ),
                ]),
              ),
            ),
            SectionCard(
              title: week ? 'Срок' : 'Объём',
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(children: [
                  IconButton.filledTonal(onPressed: _units > 1 ? () => setState(() => _units--) : null, icon: const Icon(Icons.remove_rounded)),
                  Expanded(
                    child: Text(week ? '$_units нед.' : '${_units * 1000} показов',
                        textAlign: TextAlign.center, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                  ),
                  IconButton.filledTonal(
                      onPressed: _units < f.maxUnits ? () => setState(() => _units++) : null, icon: const Icon(Icons.add_rounded)),
                ]),
                const SizedBox(height: 8),
                Text('Итого: ${formatMoney(_units * f.unitPrice)}',
                    textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800, color: kBrand, fontSize: 16)),
              ]),
            ),
            if (f.category != 'none')
              SectionCard(
                title: f.category == 'required' ? 'Категория' : 'Категория (необязательно)',
                child: OutlinedButton.icon(
                  onPressed: _pickCategory,
                  icon: const Icon(Icons.category_outlined),
                  label: Text(_category == null ? 'Выбрать' : [if (_category!.parent != null) _category!.parent!, _category!.label].join(' / ')),
                ),
              ),
          ],
          SectionCard(
            title: 'Баннер',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (_image != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: AspectRatio(aspectRatio: 3.2, child: Image.file(File(_image!.path), fit: BoxFit.cover)),
                ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () async {
                  final x = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 2400, imageQuality: 88);
                  if (x != null) setState(() => _image = x);
                },
                icon: const Icon(Icons.image_outlined),
                label: Text(_image == null ? (_resubmit ? 'Заменить изображение' : 'Выбрать изображение') : 'Заменить'),
              ),
              Text('JPEG, PNG или WebP, от 320×100, до 2 МБ', style: TextStyle(color: context.pal.muted2, fontSize: 12)),
              const SizedBox(height: 12),
              TextField(controller: _title, maxLength: 120, decoration: const InputDecoration(labelText: 'Заголовок')),
              TextField(controller: _url, keyboardType: TextInputType.url, decoration: const InputDecoration(labelText: 'Ссылка при нажатии')),
            ]),
          ),
          Padding(
            padding: EdgeInsets.fromLTRB(20, 0, 20, 0),
            child: Text('Кампания уходит на модерацию. После одобрения появится кнопка оплаты.',
                style: TextStyle(color: context.pal.muted, fontSize: 12.5)),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
              child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ),
        ],
      ),
      bottomNavigationBar: BottomActionBar(
        child: FilledButton(onPressed: _busy ? null : _submit, child: busyLabel(_busy, 'Отправить на модерацию')),
      ),
    );
  }
}
