import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'checkout_screen.dart';
import 'home_shell.dart';

class CartScreen extends StatefulWidget {
  const CartScreen({super.key, this.embedded = false});

  /// Вкладка нижнего меню (без кнопки «назад»).
  final bool embedded;

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  CartData? _cart;
  String? _error;
  final _busy = <int>{};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final session = SessionScope.read(context);
    if (!session.loggedIn) return;
    setState(() => _error = null);
    try {
      final cart = await session.api.cart();
      if (!mounted) return;
      session.setCartCount(cart.count);
      setState(() => _cart = cart);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _change(int productId, Future<CartData> Function() call) async {
    setState(() => _busy.add(productId));
    try {
      final cart = await call();
      if (!mounted) return;
      SessionScope.read(context).setCartCount(cart.count);
      setState(() => _cart = cart);
      if (cart.notice != null) showSnack(context, cart.notice!);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy.remove(productId));
    }
  }

  Future<void> _checkout() async {
    final ok = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => const CheckoutScreen()),
    );
    if (mounted) {
      _load();
      if (ok == true) showSnack(context, 'Заказ оформлен');
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final api = session.api;

    Widget body;
    if (!session.loggedIn) {
      body = const LoginPrompt(
        icon: Icons.shopping_bag_outlined,
        text: 'Войдите, чтобы собирать корзину и оформлять заказы',
      );
    } else if (_error != null && _cart == null) {
      body = ErrorView(message: _error!, onRetry: _load);
    } else if (_cart == null) {
      body = const Center(child: CircularProgressIndicator());
    } else if (_cart!.items.isEmpty) {
      body = RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          children: const [
            SizedBox(height: 120),
            EmptyView(
              icon: Icons.shopping_bag_outlined,
              title: 'Корзина пуста',
              text: 'Добавляйте товары из каталога — оформить можно сразу несколько',
            ),
          ],
        ),
      );
    } else {
      final cart = _cart!;
      body = RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.only(top: 12),
          children: [
            for (final line in cart.items)
              SectionCard(
                padding: const EdgeInsets.all(12),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    GestureDetector(
                      onTap: () => openProduct(context, line.product.id),
                      child: ProductThumb(line.product.image, size: 76),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(line.product.title, maxLines: 2, overflow: TextOverflow.ellipsis),
                          const SizedBox(height: 4),
                          Text(formatMoney(line.lineTotal),
                              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                          const SizedBox(height: 6),
                          Row(
                            children: [
                              _QtyStepper(
                                qty: line.qty,
                                max: line.available,
                                busy: _busy.contains(line.product.id),
                                onChanged: (q) => _change(line.product.id, () => api.setCartQty(line.product.id, q)),
                              ),
                              const Spacer(),
                              IconButton(
                                tooltip: 'Удалить',
                                onPressed: _busy.contains(line.product.id)
                                    ? null
                                    : () => _change(line.product.id, () => api.removeFromCart(line.product.id)),
                                icon: Icon(Icons.delete_outline_rounded, color: context.pal.muted2),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 12),
          ],
        ),
      );
    }

    final cart = _cart;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Корзина'),
        automaticallyImplyLeading: !widget.embedded,
      ),
      body: body,
      bottomNavigationBar: session.loggedIn && cart != null && cart.items.isNotEmpty
          ? BottomActionBar(
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${cart.count} шт.', style: TextStyle(color: context.pal.muted)),
                        Text(formatMoney(cart.total),
                            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                      ],
                    ),
                  ),
                  FilledButton(style: accentButton(), onPressed: _checkout, child: const Text('Оформить')),
                ],
              ),
            )
          : null,
    );
  }
}

class _QtyStepper extends StatelessWidget {
  const _QtyStepper({required this.qty, required this.max, required this.busy, required this.onChanged});

  final int qty;
  final int max;
  final bool busy;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: context.pal.subtle,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(
            visualDensity: VisualDensity.compact,
            onPressed: busy || qty <= 1 ? null : () => onChanged(qty - 1),
            icon: const Icon(Icons.remove_rounded, size: 18),
          ),
          SizedBox(
            width: 28,
            child: busy
                ? const Center(child: SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)))
                : Text('$qty', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          IconButton(
            visualDensity: VisualDensity.compact,
            onPressed: busy || qty >= max ? null : () => onChanged(qty + 1),
            icon: const Icon(Icons.add_rounded, size: 18),
          ),
        ],
      ),
    );
  }
}
