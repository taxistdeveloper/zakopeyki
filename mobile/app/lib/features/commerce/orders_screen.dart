import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/format/money.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/widgets/product_tile.dart';
import 'checkout_screen.dart';
import 'commerce_repository.dart';

class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(l.myOrders),
          bottom: TabBar(
            tabs: [
              Tab(text: l.ordersBuying),
              Tab(text: l.ordersSelling),
            ],
          ),
        ),
        body: const TabBarView(
          children: [
            _OrdersList(role: 'buying'),
            _OrdersList(role: 'selling'),
          ],
        ),
      ),
    );
  }
}

class _OrdersList extends ConsumerWidget {
  const _OrdersList({required this.role});
  final String role;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    return ref
        .watch(ordersProvider(role))
        .when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => ErrorView(
            error: e,
            onRetry: () => ref.invalidate(ordersProvider(role)),
          ),
          data: (items) => items.isEmpty
              ? MessageView(
                  icon: Icons.receipt_long_outlined,
                  title: l.emptyOrders,
                )
              : RefreshIndicator(
                  onRefresh: () => ref.refresh(ordersProvider(role).future),
                  child: ListView.separated(
                    padding: const EdgeInsets.all(12),
                    itemCount: items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _OrderTile(order: items[i]),
                  ),
                ),
        );
  }
}

class _OrderTile extends StatelessWidget {
  const _OrderTile({required this.order});
  final OrderSummary order;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    return Card(
      color: theme.colorScheme.surfaceContainerLow,
      child: InkWell(
        onTap: () => context.push('/order/${order.id}'),
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: SizedBox(
                  width: 72,
                  height: 72,
                  child: ProductImage(url: order.imageUrl),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      order.productTitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${l.orderTitle(order.id)} · ${formatTenge(order.total)}',
                      style: theme.textTheme.bodySmall,
                    ),
                    const SizedBox(height: 6),
                    _StatusChip(order: order),
                  ],
                ),
              ),
              if (order.actions.isNotEmpty)
                Icon(Icons.circle, size: 10, color: theme.colorScheme.primary),
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.order});
  final OrderSummary order;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = switch (order.status) {
      'completed' => Colors.green.shade700,
      'cancelled' || 'refunded' => scheme.outline,
      'dispute' || 'return_requested' => scheme.error,
      _ => scheme.primary,
    };
    return Text(
      order.statusLabel,
      style: TextStyle(color: color, fontWeight: FontWeight.w600),
    );
  }
}

class OrderScreen extends ConsumerStatefulWidget {
  const OrderScreen({super.key, required this.orderId});
  final int orderId;

  @override
  ConsumerState<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends ConsumerState<OrderScreen> {
  Timer? _poll;
  int _polls = 0;
  bool _busy = false;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  /// Пока заказ ждёт оплаты — периодически спрашиваем сервер (оплату подтверждает postLink Halyk).
  void _ensurePolling(OrderSummary o) {
    if (o.status != 'awaiting_payment' || _poll != null || _polls >= 20) return;
    _poll = Timer.periodic(const Duration(seconds: 3), (t) {
      _polls++;
      ref.invalidate(orderProvider(widget.orderId));
      if (_polls >= 20) t.cancel();
    });
  }

  Future<void> _run(String action, {Map<String, dynamic>? body}) async {
    setState(() => _busy = true);
    try {
      await ref
          .read(commerceRepositoryProvider)
          .action(widget.orderId, action, body: body);
      ref
        ..invalidate(orderProvider(widget.orderId))
        ..invalidate(ordersProvider('buying'))
        ..invalidate(ordersProvider('selling'));
    } on ApiException catch (e) {
      if (mounted) {
        scaffoldMessengerKey.currentState?.showSnackBar(
          SnackBar(content: Text(errorText(context, e))),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _confirm(String title, String text) async {
    final l = AppLocalizations.of(context);
    return await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: Text(title),
            content: Text(text),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: Text(l.back),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: Text(l.confirmAction),
              ),
            ],
          ),
        ) ==
        true;
  }

  Future<void> _onAction(String action, OrderSummary o) async {
    final l = AppLocalizations.of(context);
    switch (action) {
      case 'ship':
        final data = await showDialog<Map<String, String>>(
          context: context,
          builder: (_) => const _ShipDialog(),
        );
        if (data != null) await _run('ship', body: data);
      case 'delivered':
        if (await _confirm(l.deliveredTitle, l.deliveredText)) {
          await _run('delivered');
        }
      case 'confirm':
        if (await _confirm(l.confirmTitle, l.confirmText)) {
          await _run('confirm');
        }
      case 'cancel':
        if (await _confirm(l.cancelTitle, l.cancelText)) await _run('cancel');
      case 'dispute':
        final data = await showDialog<Map<String, String>>(
          context: context,
          builder: (_) => _DisputeDialog(reasons: o.disputeReasons),
        );
        if (data != null) await _run('dispute', body: data);
    }
  }

  String _actionLabel(AppLocalizations l, String a) => switch (a) {
    'ship' => l.actionShip,
    'delivered' => l.actionDelivered,
    'confirm' => l.actionConfirm,
    'cancel' => l.actionCancel,
    'dispute' => l.actionDispute,
    _ => a,
  };

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final async = ref.watch(orderProvider(widget.orderId));
    return Scaffold(
      appBar: AppBar(title: Text(l.orderTitle(widget.orderId))),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(
          error: e,
          onRetry: () => ref.invalidate(orderProvider(widget.orderId)),
        ),
        data: (o) {
          _ensurePolling(o);
          return _body(context, l, o);
        },
      ),
    );
  }

  Widget _body(BuildContext context, AppLocalizations l, OrderSummary o) {
    final theme = Theme.of(context);
    final locale = Localizations.localeOf(context).toString();
    String date(DateTime d) =>
        DateFormat.yMMMd(locale).add_Hm().format(d.toLocal());

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: SizedBox(
              width: 56,
              height: 56,
              child: ProductImage(url: o.imageUrl),
            ),
          ),
          title: Text(o.productTitle),
          subtitle: Text(l.orderQty(o.quantity)),
          onTap: () => context.push('/product/${o.productId}'),
        ),
        Row(
          children: [
            _StatusChip(order: o),
            if (o.status == 'awaiting_payment') ...[
              const SizedBox(width: 12),
              const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ],
          ],
        ),
        const SizedBox(height: 12),
        _kv(context, o.isBuyer ? l.orderSeller : l.orderBuyer, o.counterparty),
        _kv(context, l.dealSafe, o.dealMode == 'escrow' ? '✓' : l.dealDirect),
        if (o.deliveryMethod != null)
          _kv(context, l.delivery, deliveryLabel(l, o.deliveryMethod!)),
        if (o.trackingNumber != null)
          _kv(context, l.orderTracking, o.trackingNumber!),
        if (o.inspectUntil != null && o.status == 'delivered')
          _kv(context, '', l.orderInspectUntil(date(o.inspectUntil!))),
        const Divider(height: 24),
        _kv(context, l.subtotal, formatTenge(o.amount)),
        if (o.fee != null && o.isBuyer)
          _kv(context, l.dealFee, formatTenge(o.fee!)),
        if (o.isBuyer)
          _kv(context, l.totalToPay, formatTenge(o.total), bold: true),
        if (!o.isBuyer && o.sellerPayout != null)
          _kv(context, l.orderPayout, formatTenge(o.sellerPayout!), bold: true),
        const SizedBox(height: 16),
        for (final a in o.actions)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: a == 'cancel' || a == 'dispute'
                ? OutlinedButton(
                    onPressed: _busy ? null : () => _onAction(a, o),
                    child: Text(_actionLabel(l, a)),
                  )
                : FilledButton(
                    onPressed: _busy ? null : () => _onAction(a, o),
                    child: Text(_actionLabel(l, a)),
                  ),
          ),
        if (o.status == 'awaiting_payment')
          TextButton(
            onPressed: () => ref.invalidate(orderProvider(widget.orderId)),
            child: Text(l.retryPayment),
          ),
        if (!o.isFinal)
          Text(
            l.ordersOtherOnSite,
            style: theme.textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        if (o.webUrl != null)
          TextButton(
            onPressed: () => launchUrl(
              Uri.parse(o.webUrl!),
              mode: LaunchMode.inAppBrowserView,
            ),
            child: Text(l.openOnSiteDetails),
          ),
      ],
    );
  }

  Widget _kv(BuildContext context, String k, String v, {bool bold = false}) {
    final style = bold
        ? Theme.of(context).textTheme.titleMedium
              ?.copyWith(fontWeight: FontWeight.w800)
        : null;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              k,
              style:
                  style ??
                  TextStyle(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(v, textAlign: TextAlign.end, style: style),
          ),
        ],
      ),
    );
  }
}

class _ShipDialog extends StatefulWidget {
  const _ShipDialog();

  @override
  State<_ShipDialog> createState() => _ShipDialogState();
}

class _ShipDialogState extends State<_ShipDialog> {
  final _tracking = TextEditingController();
  final _carrier = TextEditingController();

  @override
  void dispose() {
    _tracking.dispose();
    _carrier.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return AlertDialog(
      title: Text(l.shipTitle),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(
            controller: _tracking,
            autofocus: true,
            decoration: InputDecoration(labelText: l.shipTracking),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _carrier,
            decoration: InputDecoration(labelText: l.shipCarrier),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(l.back),
        ),
        FilledButton(
          onPressed: _tracking.text.trim().isEmpty
              ? null
              : () => Navigator.pop(context, {
                  'tracking': _tracking.text.trim(),
                  'carrier': _carrier.text.trim(),
                }),
          child: Text(l.confirmAction),
        ),
      ],
    );
  }
}

class _DisputeDialog extends StatefulWidget {
  const _DisputeDialog({required this.reasons});
  final List<String> reasons;

  @override
  State<_DisputeDialog> createState() => _DisputeDialogState();
}

class _DisputeDialogState extends State<_DisputeDialog> {
  String? _reason;
  final _comment = TextEditingController();

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  String _label(AppLocalizations l, String r) => switch (r) {
    'not_as_described' => l.reason_not_as_described,
    'not_received' => l.reason_not_received,
    'changed_mind' => l.reason_changed_mind,
    'courier_void' => l.reason_courier_void,
    'digital_defect' => l.reason_digital_defect,
    _ => r,
  };

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return AlertDialog(
      title: Text(l.disputeTitle),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(l.disputeReason),
            RadioGroup<String>(
              groupValue: _reason,
              onChanged: (v) => setState(() => _reason = v),
              child: Column(
                children: [
                  for (final r in widget.reasons)
                    RadioListTile<String>(
                      value: r,
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      title: Text(_label(l, r)),
                    ),
                ],
              ),
            ),
            TextField(
              controller: _comment,
              minLines: 3,
              maxLines: 6,
              decoration: InputDecoration(labelText: l.disputeComment),
              onChanged: (_) => setState(() {}),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(l.back),
        ),
        FilledButton(
          onPressed: _reason == null || _comment.text.trim().length < 10
              ? null
              : () => Navigator.pop(context, {
                  'reason': _reason!,
                  'comment': _comment.text.trim(),
                }),
          child: Text(l.disputeSend),
        ),
      ],
    );
  }
}
