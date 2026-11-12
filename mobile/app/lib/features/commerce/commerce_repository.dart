import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/locale/locale_controller.dart';
import '../catalog/models.dart';

// Суммы, сборы и правила — только с сервера (Order::createEscrow, EscrowService); клиент отображает.

int _amount(Object? money) =>
    money is Map<String, dynamic> ? (money['amount'] as num?)?.toInt() ?? 0 : 0;

class CartLine {
  const CartLine({
    required this.product,
    required this.qty,
    required this.available,
    required this.lineTotal,
  });
  final ProductCard product;
  final int qty;
  final int available;
  final int lineTotal;

  factory CartLine.fromJson(Map<String, dynamic> j) => CartLine(
    product: ProductCard.fromJson(j['product'] as Map<String, dynamic>),
    qty: (j['qty'] as num?)?.toInt() ?? 1,
    available: (j['available'] as num?)?.toInt() ?? 1,
    lineTotal: _amount(j['line_total']),
  );
}

class CartState {
  const CartState({
    required this.lines,
    required this.count,
    required this.total,
    this.notice,
  });
  final List<CartLine> lines;
  final int count;
  final int total;
  final String? notice;

  factory CartState.fromJson(Map<String, dynamic> j) => CartState(
    lines: ((j['items'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(CartLine.fromJson)
        .toList(),
    count: (j['count'] as num?)?.toInt() ?? 0,
    total: _amount(j['total']),
    notice: j['notice'] as String?,
  );

  static const empty = CartState(lines: [], count: 0, total: 0);
}

class DealModeOption {
  const DealModeOption(this.code, this.fee, this.total);
  final String code; // escrow | direct
  final int fee;
  final int total;
}

class DeliveryOption {
  const DeliveryOption(this.code, this.availableInApp);
  final String code;
  final bool availableInApp;
}

class CheckoutOptions {
  const CheckoutOptions({
    required this.items,
    required this.subtotal,
    required this.dealModes,
    required this.deliveryMethods,
    required this.cardAvailable,
    required this.cardSimulated,
    required this.walletBalance,
    required this.inspectionHours,
  });

  final List<({ProductCard product, int qty, int amount})> items;
  final int subtotal;
  final List<DealModeOption> dealModes;
  final List<DeliveryOption> deliveryMethods;
  final bool cardAvailable;
  final bool cardSimulated;
  final int walletBalance;
  final int inspectionHours;

  factory CheckoutOptions.fromJson(Map<String, dynamic> j) {
    final payments = ((j['payment_methods'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>();
    final card = payments.firstWhere(
      (p) => p['code'] == 'card',
      orElse: () => const {},
    );
    final wallet = payments.firstWhere(
      (p) => p['code'] == 'wallet',
      orElse: () => const {},
    );
    return CheckoutOptions(
      items: ((j['items'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(
            (i) => (
              product: ProductCard.fromJson(
                i['product'] as Map<String, dynamic>,
              ),
              qty: (i['qty'] as num?)?.toInt() ?? 1,
              amount: (i['amount'] as num?)?.toInt() ?? 0,
            ),
          )
          .toList(),
      subtotal: _amount(j['subtotal']),
      dealModes: ((j['deal_modes'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(
            (m) => DealModeOption(
              m['code'].toString(),
              (m['fee'] as num).toInt(),
              (m['total'] as num).toInt(),
            ),
          )
          .toList(),
      deliveryMethods: ((j['delivery_methods'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(
            (m) => DeliveryOption(
              m['code'].toString(),
              m['available_in_app'] == true,
            ),
          )
          .toList(),
      cardAvailable: card['available'] == true,
      cardSimulated: card['simulated'] == true,
      walletBalance: (wallet['balance'] as num?)?.toInt() ?? 0,
      inspectionHours: (j['inspection_hours'] as num?)?.toInt() ?? 24,
    );
  }
}

/// Что оформляем: один товар или всю корзину.
class CheckoutTarget {
  const CheckoutTarget.product(int this.productId, {this.qty = 1})
    : fromCart = false;
  const CheckoutTarget.cart() : productId = null, qty = 1, fromCart = true;

  final int? productId;
  final int qty;
  final bool fromCart;

  Map<String, dynamic> get query =>
      fromCart ? {'source': 'cart'} : {'product_id': productId, 'qty': qty};

  @override
  bool operator ==(Object other) =>
      other is CheckoutTarget &&
      other.productId == productId &&
      other.qty == qty &&
      other.fromCart == fromCart;

  @override
  int get hashCode => Object.hash(productId, qty, fromCart);
}

class CheckoutResult {
  const CheckoutResult(this.orderIds, this.paid, this.paymentUrl);
  final List<int> orderIds;
  final bool paid;
  final String? paymentUrl;
}

class OrderSummary {
  const OrderSummary({
    required this.id,
    required this.role,
    required this.status,
    required this.statusLabel,
    required this.dealMode,
    required this.productId,
    required this.productTitle,
    required this.quantity,
    required this.amount,
    required this.total,
    required this.counterparty,
    required this.actions,
    required this.isFinal,
    this.imageUrl,
    this.fee,
    this.createdAt,
    this.deliveryMethod,
    this.trackingNumber,
    this.inspectUntil,
    this.sellerPayout,
    this.disputeReasons = const [],
    this.webUrl,
  });

  final int id;
  final String role; // buyer | seller
  final String status;
  final String statusLabel;
  final String dealMode;
  final int productId;
  final String productTitle;
  final String? imageUrl;
  final int quantity;
  final int amount;
  final int? fee;
  final int total;
  final String counterparty;
  final List<String> actions;
  final bool isFinal;
  final DateTime? createdAt;
  final String? deliveryMethod;
  final String? trackingNumber;
  final DateTime? inspectUntil;
  final int? sellerPayout;
  final List<String> disputeReasons;
  final String? webUrl;

  bool get isBuyer => role == 'buyer';

  factory OrderSummary.fromJson(Map<String, dynamic> j) {
    final product = (j['product'] as Map<String, dynamic>?) ?? const {};
    return OrderSummary(
      id: (j['id'] as num).toInt(),
      role: (j['role'] ?? 'buyer').toString(),
      status: (j['status'] ?? '').toString(),
      statusLabel: (j['status_label'] ?? '').toString(),
      dealMode: (j['deal_mode'] ?? 'escrow').toString(),
      productId: (product['id'] as num?)?.toInt() ?? 0,
      productTitle: (product['title'] ?? '').toString(),
      imageUrl: product['image_url'] as String?,
      quantity: (j['quantity'] as num?)?.toInt() ?? 1,
      amount: _amount(j['amount']),
      fee: j['arbitration_fee'] == null ? null : _amount(j['arbitration_fee']),
      total: _amount(j['total']),
      counterparty: (j['counterparty'] ?? '').toString(),
      actions: ((j['actions'] as List?) ?? const [])
          .map((e) => e.toString())
          .toList(),
      isFinal: j['is_final'] == true,
      createdAt: DateTime.tryParse((j['created_at'] ?? '').toString()),
      deliveryMethod: j['delivery_method'] as String?,
      trackingNumber: j['tracking_number'] as String?,
      inspectUntil: DateTime.tryParse((j['inspect_until'] ?? '').toString()),
      sellerPayout: j['seller_payout'] == null
          ? null
          : _amount(j['seller_payout']),
      disputeReasons: ((j['dispute_reasons'] as List?) ?? const [])
          .map((e) => e.toString())
          .toList(),
      webUrl: j['web_url'] as String?,
    );
  }
}

class CommerceRepository {
  const CommerceRepository(this._api);
  final ApiClient _api;

  Future<CartState> cart() async =>
      CartState.fromJson((await _api.get('/cart')).data);
  Future<CartState> add(int productId, {int qty = 1}) async =>
      CartState.fromJson(
        (await _api.post(
          '/cart/items',
          body: {'product_id': productId, 'qty': qty},
        )).data,
      );
  Future<CartState> setQty(int productId, int qty) async => CartState.fromJson(
    (await _api.post('/cart/items/$productId/qty', body: {'qty': qty})).data,
  );
  Future<CartState> remove(int productId) async => CartState.fromJson(
    (await _api.post('/cart/items/$productId/remove')).data,
  );

  Future<CheckoutOptions> options(CheckoutTarget target) async =>
      CheckoutOptions.fromJson(
        (await _api.get('/checkout/options', query: target.query)).data,
      );

  Future<CheckoutResult> checkout(
    CheckoutTarget target, {
    required String dealMode,
    required String delivery,
    required String payment,
  }) async {
    final res = await _api.post(
      '/checkout',
      body: {
        ...target.query,
        'deal_mode': dealMode,
        'delivery_method': delivery,
        'payment_method': payment,
      },
    );
    final pay = (res.data['payment'] as Map<String, dynamic>?) ?? const {};
    return CheckoutResult(
      ((res.data['order_ids'] as List?) ?? const [])
          .map((e) => (e as num).toInt())
          .toList(),
      pay['status'] == 'paid',
      pay['payment_url'] as String?,
    );
  }

  Future<List<OrderSummary>> orders(String role) async {
    final res = await _api.get('/orders', query: {'role': role});
    return ((res.data['items'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(OrderSummary.fromJson)
        .toList();
  }

  Future<OrderSummary> order(int id) async => OrderSummary.fromJson(
    (await _api.get('/orders/$id')).data['order'] as Map<String, dynamic>,
  );

  Future<OrderSummary> action(
    int id,
    String action, {
    Map<String, dynamic>? body,
  }) async => OrderSummary.fromJson(
    (await _api.post('/orders/$id/$action', body: body ?? {})).data['order']
        as Map<String, dynamic>,
  );
}

final commerceRepositoryProvider = Provider<CommerceRepository>(
  (ref) => CommerceRepository(ref.watch(apiClientProvider)),
);

/// Корзина (общая с сайтом). Гость — пустая корзина.
final cartProvider = FutureProvider<CartState>((ref) {
  ref.watch(localeProvider);
  if (ref.watch(authControllerProvider) is! AuthAuthenticated) {
    return CartState.empty;
  }
  return ref.watch(commerceRepositoryProvider).cart();
});

final checkoutOptionsProvider = FutureProvider.autoDispose
    .family<CheckoutOptions, CheckoutTarget>((ref, target) {
      ref.watch(localeProvider);
      return ref.watch(commerceRepositoryProvider).options(target);
    });

final ordersProvider = FutureProvider.autoDispose
    .family<List<OrderSummary>, String>((ref, role) {
      ref.watch(localeProvider);
      return ref.watch(commerceRepositoryProvider).orders(role);
    });

final orderProvider = FutureProvider.autoDispose.family<OrderSummary, int>((
  ref,
  id,
) {
  ref.watch(localeProvider);
  return ref.watch(commerceRepositoryProvider).order(id);
});
