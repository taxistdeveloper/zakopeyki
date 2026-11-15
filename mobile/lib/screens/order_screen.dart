import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'chats_screen.dart';
import 'orders_screen.dart';
import 'payment_screen.dart';

/// Карточка сделки и действия сторон (отправил / получил / подтвердить / спор / отмена).
class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.id});

  final int id;

  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> {
  Order? _o;
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
      final o = await SessionScope.read(context).api.order(widget.id);
      if (mounted) setState(() => _o = o);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _act(String action, [Map<String, dynamic>? body, String? done]) async {
    setState(() => _busy = true);
    try {
      final o = await SessionScope.read(context).api.orderAction(widget.id, action, body);
      if (!mounted) return;
      setState(() => _o = o);
      if (done != null) showSnack(context, done);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pay() async {
    setState(() => _busy = true);
    try {
      final url = await SessionScope.read(context).api.payOrder(widget.id);
      if (!mounted) return;
      final status = await Navigator.of(context).push<String>(
        MaterialPageRoute(fullscreenDialog: true, builder: (_) => PaymentScreen(url: url)),
      );
      if (!mounted) return;
      showSnack(context, status == 'success' ? 'Оплата прошла' : 'Оплата не завершена');
      await _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _confirm(String title, String text, String button, {bool danger = false}) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Text(text),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Назад')),
          FilledButton(
            style: danger ? FilledButton.styleFrom(backgroundColor: const Color(0xFFDC2626)) : null,
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(button),
          ),
        ],
      ),
    );
    return ok == true;
  }

  Future<void> _ship() async {
    final tracking = TextEditingController();
    final carrier = TextEditingController();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Товар отправлен', style: Theme.of(ctx).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text('Укажите трек-номер — покупатель сможет отследить посылку.',
                style: TextStyle(color: context.pal.muted)),
            const SizedBox(height: 16),
            TextField(controller: tracking, autofocus: true, decoration: const InputDecoration(labelText: 'Трек-номер')),
            const SizedBox(height: 12),
            TextField(controller: carrier, decoration: const InputDecoration(labelText: 'Служба доставки (необязательно)')),
            const SizedBox(height: 20),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Подтвердить отправку')),
          ],
        ),
      ),
    );
    if (ok == true) {
      await _act('ship', {'tracking': tracking.text.trim(), 'carrier': carrier.text.trim()}, 'Отправка подтверждена');
    }
    tracking.dispose();
    carrier.dispose();
  }

  Future<void> _dispute(Order o) async {
    final comment = TextEditingController();
    String? reason = o.disputeReasons.firstOrNull;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('Проблема с заказом',
                    style: Theme.of(ctx).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                Text('Деньги останутся заблокированы, пока вы не договоритесь с продавцом или не решит арбитраж.',
                    style: TextStyle(color: context.pal.muted)),
                const SizedBox(height: 8),
                RadioGroup<String>(
                  groupValue: reason,
                  onChanged: (v) => setLocal(() => reason = v),
                  child: Column(
                    children: [
                      for (final r in o.disputeReasons)
                        RadioListTile<String>(
                          value: r,
                          contentPadding: EdgeInsets.zero,
                          title: Text(disputeReasonLabel(r)),
                        ),
                    ],
                  ),
                ),
                TextField(
                  controller: comment,
                  minLines: 3,
                  maxLines: 6,
                  decoration: const InputDecoration(labelText: 'Опишите проблему'),
                ),
                const SizedBox(height: 16),
                FilledButton(
                  style: FilledButton.styleFrom(backgroundColor: const Color(0xFFDC2626)),
                  onPressed: reason == null ? null : () => Navigator.pop(ctx, true),
                  child: const Text('Открыть спор'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    if (ok == true && reason != null) {
      await _act('dispute', {'reason': reason, 'comment': comment.text.trim()}, 'Спор открыт');
    }
    comment.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final o = _o;
    return Scaffold(
      appBar: AppBar(title: Text('Заказ №${widget.id}')),
      body: o == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.only(top: 12, bottom: 12),
                children: [
                  GestureDetector(
                    onTap: () => openProduct(context, o.productId),
                    child: SectionCard(
                      child: Row(
                        children: [
                          ProductThumb(o.productImage, size: 72),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(o.productTitle, style: const TextStyle(fontWeight: FontWeight.w600)),
                                const SizedBox(height: 4),
                                Text('${o.quantity} шт. · ${o.isBuyer ? 'Продавец' : 'Покупатель'}: ${o.counterparty}',
                                    style: TextStyle(color: context.pal.muted, fontSize: 13)),
                                const SizedBox(height: 6),
                                StatusPill(o.statusLabel, color: orderStatusColor(o)),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                    child: OutlinedButton.icon(
                      onPressed: () => openChatFor(context, orderId: o.id),
                      style: OutlinedButton.styleFrom(backgroundColor: context.pal.surface),
                      icon: const Icon(Icons.chat_bubble_outline_rounded, size: 18),
                      label: Text(o.isBuyer ? 'Написать продавцу' : 'Написать покупателю'),
                    ),
                  ),
                  if (_hint(o) != null)
                    SectionCard(
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.info_outline_rounded, color: kBrand),
                          const SizedBox(width: 10),
                          Expanded(child: Text(_hint(o)!)),
                        ],
                      ),
                    ),
                  SectionCard(
                    title: 'Сумма',
                    child: Column(
                      children: [
                        AmountRow('Товар', formatMoney(o.amount)),
                        if (o.fee > 0) AmountRow('Сбор безопасной сделки', formatMoney(o.fee)),
                        const Divider(height: 18),
                        AmountRow(o.isBuyer ? 'Оплачено' : 'Сумма заказа', formatMoney(o.total), bold: true),
                        if (o.sellerPayout != null)
                          AmountRow('Вы получите', formatMoney(o.sellerPayout!), color: const Color(0xFF16A34A)),
                      ],
                    ),
                  ),
                  SectionCard(
                    title: 'Детали',
                    child: Column(
                      children: [
                        AmountRow('Оформлен', formatDate(o.createdAt, withTime: true)),
                        AmountRow('Сделка', o.dealMode == 'escrow' ? 'Безопасная' : 'Напрямую'),
                        if (o.deliveryMethod != null && o.deliveryMethod!.isNotEmpty)
                          AmountRow('Доставка', deliveryLabel(o.deliveryMethod!)),
                        if (o.tracking != null) AmountRow('Трек-номер', o.tracking!),
                        if (o.carrier != null) AmountRow('Служба', o.carrier!),
                        if (o.inspectUntil != null) AmountRow('Проверка до', formatDate(o.inspectUntil, withTime: true)),
                      ],
                    ),
                  ),
                  if (o.canReview) _ReviewForm(order: o, onSaved: (u) => setState(() => _o = u)),
                  if (o.myRating != null)
                    SectionCard(
                      title: 'Ваш отзыв',
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(children: [
                            for (var i = 1; i <= 5; i++)
                              Icon(Icons.star_rounded,
                                  color: i <= o.myRating! ? const Color(0xFFF59E0B) : context.pal.hairline),
                          ]),
                          if (o.myReviewText != null) ...[const SizedBox(height: 6), Text(o.myReviewText!)],
                        ],
                      ),
                    ),
                ],
              ),
            ),
      bottomNavigationBar: o == null || o.actions.isEmpty
          ? null
          : BottomActionBar(child: _actions(o)),
    );
  }

  String? _hint(Order o) {
    if (o.isFinal) return null;
    if (o.isBuyer) {
      return switch (o.status) {
        'awaiting_payment' => 'Заказ ещё не оплачен. Оплатите его в течение суток или отмените.',
        'escrowed' => 'Оплата получена и хранится на безопасной сделке. Ждём, когда продавец отправит товар.',
        'shipped' => 'Продавец отправил товар. Когда получите — отметьте получение и проверьте товар.',
        'delivered' => 'Проверьте товар. Если всё в порядке — подтвердите, и деньги уйдут продавцу.',
        _ => null,
      };
    }
    return switch (o.status) {
      'escrowed' => 'Покупатель оплатил заказ. Отправьте товар и укажите трек-номер.',
      'shipped' => 'Ждём, когда покупатель получит и подтвердит заказ.',
      'delivered' => 'Покупатель проверяет товар. После подтверждения деньги поступят на ваш кошелёк.',
      _ => null,
    };
  }

  Widget _actions(Order o) {
    final buttons = <Widget>[];
    for (final a in o.actions) {
      switch (a) {
        case 'ship':
          buttons.add(FilledButton(onPressed: _busy ? null : _ship, child: busyLabel(_busy, 'Я отправил товар')));
        case 'delivered':
          buttons.add(OutlinedButton(
            onPressed: _busy ? null : () => _act('delivered', null, 'Отмечено: товар получен'),
            child: const Text('Товар получен'),
          ));
        case 'confirm':
          buttons.add(FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    if (await _confirm('Подтвердить получение?',
                        'Деньги будут переведены продавцу. Открыть спор после этого будет нельзя.', 'Подтвердить')) {
                      await _act('confirm', null, 'Сделка завершена');
                    }
                  },
            child: busyLabel(_busy, 'Всё в порядке'),
          ));
        case 'dispute':
          buttons.add(TextButton(
            onPressed: _busy ? null : () => _dispute(o),
            style: TextButton.styleFrom(foregroundColor: const Color(0xFFDC2626)),
            child: const Text('Есть проблема'),
          ));
        case 'pay':
          buttons.add(FilledButton(onPressed: _busy ? null : _pay, child: busyLabel(_busy, 'Оплатить ${formatMoney(o.total)}')));
        case 'cancel':
          buttons.add(OutlinedButton(
            onPressed: _busy
                ? null
                : () async {
                    if (await _confirm('Отменить заказ?', 'Деньги вернутся на ваш кошелёк или карту.', 'Отменить заказ',
                        danger: true)) {
                      await _act('cancel', null, 'Заказ отменён');
                    }
                  },
            style: OutlinedButton.styleFrom(foregroundColor: const Color(0xFFDC2626)),
            child: const Text('Отменить заказ'),
          ));
      }
    }
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < buttons.length; i++) ...[
          if (i > 0) const SizedBox(height: 8),
          buttons[i],
        ],
      ],
    );
  }
}


/// Оценка и текст после завершённой сделки (один раз).
class _ReviewForm extends StatefulWidget {
  const _ReviewForm({required this.order, required this.onSaved});

  final Order order;
  final ValueChanged<Order> onSaved;

  @override
  State<_ReviewForm> createState() => _ReviewFormState();
}

class _ReviewFormState extends State<_ReviewForm> {
  int _rating = 0;
  bool _busy = false;
  final _text = TextEditingController();

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _busy = true);
    try {
      final o = await SessionScope.read(context).api.reviewOrder(widget.order.id, _rating, _text.text.trim());
      if (!mounted) return;
      showSnack(context, 'Спасибо за отзыв!');
      widget.onSaved(o);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final whom = widget.order.isBuyer ? 'продавца' : 'покупателя';
    return SectionCard(
      title: 'Оцените $whom',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('${widget.order.counterparty} · отзыв увидят другие пользователи',
              style: TextStyle(color: context.pal.muted, fontSize: 13)),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var i = 1; i <= 5; i++)
                IconButton(
                  onPressed: () => setState(() => _rating = i),
                  iconSize: 38,
                  icon: Icon(Icons.star_rounded, color: i <= _rating ? const Color(0xFFF59E0B) : context.pal.hairline),
                ),
            ],
          ),
          if (_rating > 0) ...[
            TextField(
              controller: _text,
              minLines: 2,
              maxLines: 6,
              maxLength: 2000,
              textCapitalization: TextCapitalization.sentences,
              decoration: const InputDecoration(hintText: 'Что понравилось или нет (необязательно)'),
            ),
            const SizedBox(height: 8),
            FilledButton(onPressed: _busy ? null : _submit, child: busyLabel(_busy, 'Отправить отзыв')),
          ],
        ],
      ),
    );
  }
}
