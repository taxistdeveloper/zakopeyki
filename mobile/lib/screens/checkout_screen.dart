import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'orders_screen.dart';
import 'payment_screen.dart';

/// Оформление: один товар ([productId]) или вся корзина. Все суммы считает сервер.
/// Возвращает true, если заказ создан и оплачен.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key, this.productId, this.qty = 1});

  final int? productId;
  final int qty;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  CheckoutOptions? _o;
  String? _error;
  String _deal = 'escrow';
  String? _delivery;
  String? _payment;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final o = await SessionScope.read(context).api.checkoutOptions(productId: widget.productId, qty: widget.qty);
      if (!mounted) return;
      setState(() {
        _o = o;
        _delivery = o.deliveryMethods.where((d) => d.availableInApp).firstOrNull?.code;
        _payment = o.cardAvailable ? 'card' : 'wallet';
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  DealMode? get _mode => _o?.dealModes.where((m) => m.code == _deal).firstOrNull ?? _o?.dealModes.firstOrNull;

  bool get _walletEnough => (_o?.walletBalance ?? 0) >= (_mode?.total ?? 0);

  Future<void> _submit() async {
    final o = _o;
    if (o == null || _delivery == null || _payment == null) return;
    if (_payment == 'wallet' && !_walletEnough) {
      showSnack(context, 'На кошельке недостаточно средств');
      return;
    }
    setState(() => _busy = true);
    final session = SessionScope.read(context);
    try {
      final r = await session.api.checkout(
        productId: widget.productId,
        qty: widget.qty,
        dealMode: _mode?.code ?? 'escrow',
        delivery: _delivery!,
        payment: _payment!,
      );
      session.refreshCartCount();
      if (!mounted) return;

      var status = r.paid ? 'success' : null;
      if (!r.paid && r.paymentUrl != null) {
        status = await Navigator.of(context).push<String>(
          MaterialPageRoute(fullscreenDialog: true, builder: (_) => PaymentScreen(url: r.paymentUrl!)),
        );
      }
      if (!mounted) return;
      await _showResult(status == 'success');
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _showResult(bool ok) async {
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        icon: Icon(ok ? Icons.check_circle_rounded : Icons.schedule_rounded,
            size: 48, color: ok ? const Color(0xFF16A34A) : const Color(0xFFF59E0B)),
        title: Text(ok ? 'Заказ оплачен' : 'Заказ ждёт оплаты'),
        content: Text(ok
            ? (_deal == 'escrow'
                ? 'Деньги хранятся на безопасной сделке, пока вы не подтвердите получение.'
                : 'Продавец получил уведомление о заказе.')
            : 'Оплата не завершена. Оплатить или отменить заказ можно в разделе «Мои покупки».'),
        actions: [
          FilledButton(
            onPressed: () {
              Navigator.pop(ctx);
              Navigator.of(context).pushReplacement(MaterialPageRoute(
                builder: (_) => const OrdersScreen(),
              ));
            },
            child: const Text('К заказам'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final o = _o;
    return Scaffold(
      appBar: AppBar(title: const Text('Оформление заказа')),
      body: o == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.only(top: 12, bottom: 12),
              children: [
                SectionCard(
                  title: o.items.length == 1 ? 'Товар' : 'Товары (${o.items.length})',
                  child: Column(
                    children: [
                      for (final l in o.items)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          child: Row(
                            children: [
                              ProductThumb(l.product.image, size: 52),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(l.product.title, maxLines: 2, overflow: TextOverflow.ellipsis),
                                    Text('${l.qty} шт.', style: const TextStyle(color: Colors.black54, fontSize: 13)),
                                  ],
                                ),
                              ),
                              Text(formatMoney(l.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
                if (o.dealModes.length > 1)
                  SectionCard(
                    title: 'Как оплатить продавцу',
                    child: Column(
                      children: [
                        for (final m in o.dealModes)
                          _Choice(
                            selected: _deal == m.code,
                            onTap: () => setState(() => _deal = m.code),
                            icon: m.code == 'escrow' ? Icons.verified_user_rounded : Icons.handshake_outlined,
                            title: m.code == 'escrow' ? 'Безопасная сделка' : 'Напрямую продавцу',
                            subtitle: m.code == 'escrow'
                                ? 'Деньги у сервиса до получения товара · ${o.inspectionHours} ч на проверку · сбор ${formatMoney(m.fee)}'
                                : 'Без защиты покупателя и без сбора',
                          ),
                      ],
                    ),
                  )
                else
                  SectionCard(
                    child: Row(
                      children: [
                        const Icon(Icons.verified_user_rounded, color: kBrand),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            'Безопасная сделка: деньги получит продавец только после того, как вы подтвердите получение '
                            '(${o.inspectionHours} ч на проверку).',
                            style: const TextStyle(fontSize: 13),
                          ),
                        ),
                      ],
                    ),
                  ),
                SectionCard(
                  title: 'Доставка',
                  child: Column(
                    children: [
                      for (final d in o.deliveryMethods)
                        _Choice(
                          selected: _delivery == d.code,
                          enabled: d.availableInApp,
                          onTap: () => setState(() => _delivery = d.code),
                          icon: switch (d.code) {
                            'pickup' => Icons.storefront_rounded,
                            'courier' => Icons.delivery_dining_rounded,
                            'digital' => Icons.cloud_download_outlined,
                            _ => Icons.local_shipping_outlined,
                          },
                          title: d.label,
                          subtitle: d.availableInApp ? null : 'Пункт выдачи выбирается на сайте',
                        ),
                    ],
                  ),
                ),
                SectionCard(
                  title: 'Оплата',
                  child: Column(
                    children: [
                      _Choice(
                        selected: _payment == 'card',
                        enabled: o.cardAvailable,
                        onTap: () => setState(() => _payment = 'card'),
                        icon: Icons.credit_card_rounded,
                        title: 'Банковской картой',
                        subtitle: !o.cardAvailable
                            ? 'Временно недоступно'
                            : (o.cardSimulated ? 'Тестовый режим оплаты' : 'Visa, Mastercard · Halyk ePay'),
                      ),
                      _Choice(
                        selected: _payment == 'wallet',
                        enabled: _walletEnough,
                        onTap: () => setState(() => _payment = 'wallet'),
                        icon: Icons.account_balance_wallet_outlined,
                        title: 'С кошелька',
                        subtitle: 'Баланс ${formatMoney(o.walletBalance)}${_walletEnough ? '' : ' — недостаточно'}',
                      ),
                    ],
                  ),
                ),
                SectionCard(
                  child: Column(
                    children: [
                      AmountRow('Товары', formatMoney(o.subtotal)),
                      if ((_mode?.fee ?? 0) > 0) AmountRow('Сбор безопасной сделки', formatMoney(_mode!.fee)),
                      const Divider(height: 18),
                      AmountRow('Итого', formatMoney(_mode?.total ?? o.subtotal), bold: true),
                    ],
                  ),
                ),
              ],
            ),
      bottomNavigationBar: o == null
          ? null
          : BottomActionBar(
              child: FilledButton(
                onPressed: _busy || _delivery == null || _payment == null ? null : _submit,
                child: busyLabel(_busy, 'Оплатить ${formatMoney(_mode?.total ?? o.subtotal)}'),
              ),
            ),
    );
  }
}

class _Choice extends StatelessWidget {
  const _Choice({
    required this.selected,
    required this.onTap,
    required this.icon,
    required this.title,
    this.subtitle,
    this.enabled = true,
  });

  final bool selected;
  final bool enabled;
  final VoidCallback onTap;
  final IconData icon;
  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    final active = selected && enabled;
    return Opacity(
      opacity: enabled ? 1 : 0.45,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(12),
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: active ? const Color(0xFFEFF6FF) : Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: active ? kBrand : const Color(0xFFE2E8F0), width: active ? 1.5 : 1),
            ),
            child: Row(
              children: [
                Icon(icon, color: active ? kBrand : Colors.black54),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
                      if (subtitle != null)
                        Text(subtitle!, style: const TextStyle(color: Colors.black54, fontSize: 12.5)),
                    ],
                  ),
                ),
                Icon(active ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                    color: active ? kBrand : Colors.black26),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
