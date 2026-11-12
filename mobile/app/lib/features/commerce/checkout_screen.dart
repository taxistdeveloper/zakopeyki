import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_web_auth_2/flutter_web_auth_2.dart';
import 'package:go_router/go_router.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/format/money.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/feed_controller.dart';
import 'commerce_repository.dart';

/// Открыть платёжную форму Halyk и дождаться возврата `zakopeyki://payment-result`.
/// Возвращает status из ссылки или null, если пользователь закрыл окно.
/// Результату НЕ доверяем — статус заказа приложение запрашивает у сервера (его подтверждает postLink).
typedef PaymentLauncher = Future<String?> Function(String url);

final paymentLauncherProvider = Provider<PaymentLauncher>(
  (ref) => (url) async {
    try {
      final result = await FlutterWebAuth2.authenticate(
        url: url,
        callbackUrlScheme: 'zakopeyki',
      );
      return Uri.parse(result).queryParameters['status'];
    } on PlatformException {
      return null; // окно закрыто пользователем
    }
  },
);

String deliveryLabel(AppLocalizations l, String code) => switch (code) {
  'pickup' => l.deliveryPickup,
  'kazpost' => l.deliveryKazpost,
  'courier' => l.deliveryCourier,
  'cdek' => l.deliveryCdek,
  'digital' => l.deliveryDigital,
  _ => l.deliveryOther,
};

class CheckoutScreen extends ConsumerStatefulWidget {
  const CheckoutScreen({super.key, required this.target});
  final CheckoutTarget target;

  @override
  ConsumerState<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends ConsumerState<CheckoutScreen> {
  String _deal = 'escrow';
  String? _delivery;
  String _payment = 'card';
  bool _busy = false;
  String? _error;

  Future<void> _pay(CheckoutOptions o) async {
    final l = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = await ref
          .read(commerceRepositoryProvider)
          .checkout(
            widget.target,
            dealMode: _deal,
            delivery: _delivery!,
            payment: _payment,
          );
      ref
        ..invalidate(cartProvider)
        ..invalidate(ordersProvider('buying'));
      ref.read(feedControllerProvider.notifier).refresh();

      var cancelled = false;
      if (!res.paid && res.paymentUrl != null) {
        final status = await ref.read(paymentLauncherProvider)(res.paymentUrl!);
        cancelled = status == null || status == 'failure';
      }
      if (!mounted) return;
      final target = res.orderIds.length == 1
          ? '/order/${res.orderIds.first}'
          : '/orders';
      context.go('/profile');
      context.push(target);
      showMessageAfterNavigation(
        cancelled ? l.paymentCancelled : l.orderPlaced,
      );
    } on ApiException catch (e) {
      if (mounted) {
        setState(
          () => _error = e.fields.values.firstOrNull ?? errorText(context, e),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l.checkoutTitle)),
      body: ref
          .watch(checkoutOptionsProvider(widget.target))
          .when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => ErrorView(
              error: e,
              onRetry: () =>
                  ref.invalidate(checkoutOptionsProvider(widget.target)),
            ),
            data: (o) => _body(context, l, o),
          ),
    );
  }

  Widget _body(BuildContext context, AppLocalizations l, CheckoutOptions o) {
    final theme = Theme.of(context);
    _delivery ??= o.deliveryMethods
        .where((d) => d.availableInApp)
        .map((d) => d.code)
        .firstOrNull;
    final deal = o.dealModes.firstWhere(
      (m) => m.code == _deal,
      orElse: () => o.dealModes.first,
    );
    if (!o.cardAvailable && _payment == 'card') _payment = 'wallet';
    final walletShort = _payment == 'wallet' && o.walletBalance < deal.total;
    final canPay =
        !_busy &&
        _delivery != null &&
        !walletShort &&
        (_payment != 'card' || o.cardAvailable);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        for (final item in o.items)
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(
              item.product.title,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            subtitle: Text('× ${item.qty}'),
            trailing: Text(
              formatTenge(item.amount),
              style: theme.textTheme.titleSmall,
            ),
          ),
        const Divider(),

        // ---- режим сделки: выбор покупателя (Safe Deal по умолчанию)
        RadioGroup<String>(
          groupValue: _deal,
          onChanged: (v) => setState(() => _deal = v!),
          child: Column(
            children: [
              for (final m in o.dealModes)
                RadioListTile<String>(
                  value: m.code,
                  contentPadding: EdgeInsets.zero,
                  title: Text(
                    m.code == 'escrow' ? l.dealSafe : l.dealDirect,
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  subtitle: Text(
                    m.code == 'escrow'
                        ? l.dealSafeHint(o.inspectionHours)
                        : l.dealDirectHint,
                  ),
                  secondary: Icon(
                    m.code == 'escrow'
                        ? Icons.verified_user_outlined
                        : Icons.handshake_outlined,
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 8),

        // ---- получение
        Text(l.delivery, style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final d in o.deliveryMethods)
              ChoiceChip(
                label: Text(deliveryLabel(l, d.code)),
                selected: _delivery == d.code,
                onSelected: d.availableInApp
                    ? (_) => setState(() => _delivery = d.code)
                    : null,
              ),
          ],
        ),
        if (o.deliveryMethods.any((d) => !d.availableInApp))
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(l.deliveryCdekOnSite, style: theme.textTheme.bodySmall),
          ),
        const SizedBox(height: 16),

        // ---- оплата
        Text(l.payment, style: theme.textTheme.titleMedium),
        RadioGroup<String>(
          groupValue: _payment,
          onChanged: (v) => setState(() => _payment = v!),
          child: Column(
            children: [
              RadioListTile<String>(
                value: 'card',
                enabled: o.cardAvailable,
                contentPadding: EdgeInsets.zero,
                title: Text(o.cardSimulated ? l.payCardSimulated : l.payCard),
                secondary: const Icon(Icons.credit_card),
              ),
              RadioListTile<String>(
                value: 'wallet',
                contentPadding: EdgeInsets.zero,
                title: Text(l.payWallet),
                subtitle: Text(l.walletBalance(formatTenge(o.walletBalance))),
                secondary: const Icon(Icons.account_balance_wallet_outlined),
              ),
            ],
          ),
        ),
        if (walletShort)
          Text(
            l.walletNotEnough,
            style: TextStyle(color: theme.colorScheme.error),
          ),
        const Divider(height: 32),

        // ---- суммы (с сервера)
        _row(context, l.subtotal, formatTenge(o.subtotal)),
        if (deal.fee > 0) _row(context, l.dealFee, formatTenge(deal.fee)),
        _row(context, l.totalToPay, formatTenge(deal.total), bold: true),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(
            _error!,
            style: TextStyle(color: theme.colorScheme.error),
            textAlign: TextAlign.center,
          ),
        ],
        const SizedBox(height: 16),
        FilledButton(
          onPressed: canPay ? () => _pay(o) : null,
          child: _busy
              ? const SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(strokeWidth: 2.5),
                )
              : Text(l.payButton(formatTenge(deal.total))),
        ),
      ],
    );
  }

  Widget _row(
    BuildContext context,
    String label,
    String value, {
    bool bold = false,
  }) {
    final style = bold
        ? Theme.of(context).textTheme.titleLarge
              ?.copyWith(fontWeight: FontWeight.w800)
        : Theme.of(context).textTheme.bodyLarge;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Text(label, style: style),
          const Spacer(),
          Text(value, style: style),
        ],
      ),
    );
  }
}
