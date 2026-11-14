import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../shop_models.dart';
import '../widgets/common.dart';
import 'order_screen.dart';

/// Мои покупки / продажи.
class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key, this.initialRole = 'buying'});

  final String initialRole;

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      initialIndex: initialRole == 'selling' ? 1 : 0,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Заказы'),
          bottom: const TabBar(tabs: [Tab(text: 'Покупки'), Tab(text: 'Продажи')]),
        ),
        body: const TabBarView(
          children: [_OrderList(role: 'buying'), _OrderList(role: 'selling')],
        ),
      ),
    );
  }
}

class _OrderList extends StatefulWidget {
  const _OrderList({required this.role});

  final String role;

  @override
  State<_OrderList> createState() => _OrderListState();
}

class _OrderListState extends State<_OrderList> with AutomaticKeepAliveClientMixin {
  List<Order>? _items;
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
      final items = await SessionScope.read(context).api.orders(widget.role);
      if (mounted) setState(() => _items = items);
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
    if (items.isEmpty) {
      return RefreshIndicator(
        onRefresh: _load,
        child: ListView(children: [
          const SizedBox(height: 120),
          EmptyView(
            icon: widget.role == 'buying' ? Icons.shopping_bag_outlined : Icons.sell_outlined,
            title: widget.role == 'buying' ? 'Покупок пока нет' : 'Продаж пока нет',
          ),
        ]),
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.only(top: 12),
        itemCount: items.length,
        itemBuilder: (_, i) => OrderTile(
          order: items[i],
          onTap: () async {
            await Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(id: items[i].id)));
            if (mounted) _load();
          },
        ),
      ),
    );
  }
}

class OrderTile extends StatelessWidget {
  const OrderTile({super.key, required this.order, required this.onTap});

  final Order order;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final o = order;
    return GestureDetector(
      onTap: onTap,
      child: SectionCard(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            ProductThumb(o.productImage, size: 64),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(o.productTitle, maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 2),
                  Text('№${o.id} · ${formatDate(o.createdAt)} · ${o.counterparty}',
                      maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: Colors.black54, fontSize: 12.5)),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      StatusPill(o.statusLabel, color: orderStatusColor(o)),
                      const Spacer(),
                      Text(formatMoney(o.total), style: const TextStyle(fontWeight: FontWeight.w800)),
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

Color orderStatusColor(Order o) {
  if (o.actions.isNotEmpty) return const Color(0xFFF59E0B);
  return switch (o.status) {
    'completed' => const Color(0xFF16A34A),
    'cancelled' || 'refunded' || 'partial_refunded' => Colors.black45,
    'disputed' || 'return_requested' => const Color(0xFFDC2626),
    _ => const Color(0xFF2563EB),
  };
}
