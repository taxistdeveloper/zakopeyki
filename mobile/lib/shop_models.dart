// Корзина, оформление, заказы, объявления продавца (контракт /api/mobile/v1).

import 'models.dart';

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;

int _money(Object? v) => v is Map ? _int(v['amount']) : _int(v);

String formatMoney(int amount) {
  final s = amount.abs().toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(' ');
    b.write(s[i]);
  }
  return '${amount < 0 ? '−' : ''}$b ₸';
}

String formatDate(String? iso, {bool withTime = false}) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (d == null) return '';
  String two(int v) => v.toString().padLeft(2, '0');
  final date = '${two(d.day)}.${two(d.month)}.${d.year}';
  return withTime ? '$date ${two(d.hour)}:${two(d.minute)}' : date;
}

// ---------------------------------------------------------------- корзина

class CartLine {
  final ProductCard product;
  final int qty;
  final int available;
  final int lineTotal;

  CartLine(this.product, this.qty, this.available, this.lineTotal);

  factory CartLine.fromJson(Map<String, dynamic> j) => CartLine(
        ProductCard.fromJson(j['product'] as Map<String, dynamic>),
        _int(j['qty'], 1),
        _int(j['available'], 1),
        _money(j['line_total']),
      );
}

class CartData {
  final List<CartLine> items;
  final int count;
  final int total;
  final String? notice;

  CartData(this.items, this.count, this.total, this.notice);

  static final empty = CartData(const [], 0, 0, null);

  factory CartData.fromJson(Map<String, dynamic> j) => CartData(
        ((j['items'] as List?) ?? []).map((e) => CartLine.fromJson(e as Map<String, dynamic>)).toList(),
        _int(j['count']),
        _money(j['total']),
        j['notice'] as String?,
      );
}

// ---------------------------------------------------------------- оформление

class DealMode {
  final String code;
  final int fee;
  final int total;
  DealMode(this.code, this.fee, this.total);
}

class DeliveryMethod {
  final String code;
  final bool availableInApp;
  DeliveryMethod(this.code, this.availableInApp);

  String get label => deliveryLabel(code);
}

String deliveryLabel(String code) => switch (code) {
      'pickup' => 'Самовывоз',
      'kazpost' => 'Казпочта',
      'cdek' => 'СДЭК',
      'courier' => 'Курьер',
      'digital' => 'Цифровой товар',
      'other' => 'Другой способ',
      _ => code,
    };

class CheckoutLine {
  final ProductCard product;
  final int qty;
  final int amount;
  CheckoutLine(this.product, this.qty, this.amount);
}

class CheckoutOptions {
  final List<CheckoutLine> items;
  final int subtotal;
  final List<DealMode> dealModes;
  final List<DeliveryMethod> deliveryMethods;
  final bool cardAvailable;
  final bool cardSimulated;
  final int walletBalance;
  final int inspectionHours;

  CheckoutOptions({
    required this.items,
    required this.subtotal,
    required this.dealModes,
    required this.deliveryMethods,
    required this.cardAvailable,
    required this.cardSimulated,
    required this.walletBalance,
    required this.inspectionHours,
  });

  factory CheckoutOptions.fromJson(Map<String, dynamic> j) {
    final pay = ((j['payment_methods'] as List?) ?? []).cast<Map<String, dynamic>>();
    Map<String, dynamic> payOf(String code) =>
        pay.firstWhere((p) => p['code'] == code, orElse: () => const {});
    return CheckoutOptions(
      items: ((j['items'] as List?) ?? [])
          .map((e) => CheckoutLine(
                ProductCard.fromJson(e['product'] as Map<String, dynamic>),
                _int(e['qty'], 1),
                _int(e['amount']),
              ))
          .toList(),
      subtotal: _money(j['subtotal']),
      dealModes: ((j['deal_modes'] as List?) ?? [])
          .map((e) => DealMode(_str(e['code']), _int(e['fee']), _int(e['total'])))
          .toList(),
      deliveryMethods: ((j['delivery_methods'] as List?) ?? [])
          .map((e) => DeliveryMethod(_str(e['code']), e['available_in_app'] == true))
          .toList(),
      cardAvailable: payOf('card')['available'] == true,
      cardSimulated: payOf('card')['simulated'] == true,
      walletBalance: _int(payOf('wallet')['balance']),
      inspectionHours: _int(j['inspection_hours'], 24),
    );
  }
}

class CheckoutResult {
  final List<int> orderIds;
  final bool paid;
  final String? paymentUrl;
  CheckoutResult(this.orderIds, this.paid, this.paymentUrl);

  factory CheckoutResult.fromJson(Map<String, dynamic> j) {
    final p = (j['payment'] as Map?)?.cast<String, dynamic>() ?? const {};
    return CheckoutResult(
      ((j['order_ids'] as List?) ?? []).map((e) => _int(e)).toList(),
      p['status'] == 'paid',
      p['payment_url'] as String?,
    );
  }
}

// ---------------------------------------------------------------- заказы

class Order {
  final int id;
  final String role; // buyer | seller
  final String status;
  final String statusLabel;
  final String dealMode;
  final int productId;
  final String productTitle;
  final String? productImage;
  final int quantity;
  final int amount;
  final int fee;
  final int total;
  final String counterparty;
  final String? createdAt;
  final bool isFinal;
  final List<String> actions;
  // только в карточке заказа
  final String? deliveryMethod;
  final String? tracking;
  final String? carrier;
  final String? inspectUntil;
  final int? sellerPayout;
  final List<String> disputeReasons;

  Order({
    required this.id,
    required this.role,
    required this.status,
    required this.statusLabel,
    required this.dealMode,
    required this.productId,
    required this.productTitle,
    required this.productImage,
    required this.quantity,
    required this.amount,
    required this.fee,
    required this.total,
    required this.counterparty,
    required this.createdAt,
    required this.isFinal,
    required this.actions,
    this.deliveryMethod,
    this.tracking,
    this.carrier,
    this.inspectUntil,
    this.sellerPayout,
    this.disputeReasons = const [],
  });

  bool get isBuyer => role == 'buyer';

  factory Order.fromJson(Map<String, dynamic> j) {
    final p = (j['product'] as Map?)?.cast<String, dynamic>() ?? const {};
    return Order(
      id: _int(j['id']),
      role: _str(j['role'], 'buyer'),
      status: _str(j['status']),
      statusLabel: _str(j['status_label'], _str(j['status'])),
      dealMode: _str(j['deal_mode'], 'escrow'),
      productId: _int(p['id']),
      productTitle: _str(p['title']),
      productImage: p['image_url'] as String?,
      quantity: _int(j['quantity'], 1),
      amount: _money(j['amount']),
      fee: _money(j['arbitration_fee']),
      total: _money(j['total']),
      counterparty: _str(j['counterparty']),
      createdAt: j['created_at'] as String?,
      isFinal: j['is_final'] == true,
      actions: ((j['actions'] as List?) ?? []).map((e) => e.toString()).toList(),
      deliveryMethod: j['delivery_method'] as String?,
      tracking: j['tracking_number'] as String?,
      carrier: j['carrier'] as String?,
      inspectUntil: j['inspect_until'] as String?,
      sellerPayout: j['seller_payout'] == null ? null : _money(j['seller_payout']),
      disputeReasons: ((j['dispute_reasons'] as List?) ?? []).map((e) => e.toString()).toList(),
    );
  }
}

String disputeReasonLabel(String code) => switch (code) {
      'not_as_described' => 'Товар не соответствует описанию',
      'not_received' => 'Товар не получен',
      'changed_mind' => 'Передумал(а), претензий к описанию нет',
      'courier_void' => 'Курьер не доставил / посылка утеряна',
      'digital_defect' => 'Цифровой товар не работает',
      _ => code,
    };

// ---------------------------------------------------------------- объявления

class ListingTypeSpec {
  final String code;
  final String label;
  final bool hasPrice;
  final bool hasCategory;
  final bool hasQuantity;
  final bool requiresExchangeFor;

  ListingTypeSpec(this.code, this.label, this.hasPrice, this.hasCategory, this.hasQuantity, this.requiresExchangeFor);
}

class ListingForm {
  final bool rulesAccepted;
  final String taxIdKind; // iin | bin
  final bool taxIdOnFile;
  final int maxPhotos;
  final bool canPublish;
  final String? publishBlockReason;
  final String defaultLocation;
  final List<ListingTypeSpec> types;

  ListingForm({
    required this.rulesAccepted,
    required this.taxIdKind,
    required this.taxIdOnFile,
    required this.maxPhotos,
    required this.canPublish,
    required this.publishBlockReason,
    required this.defaultLocation,
    required this.types,
  });

  factory ListingForm.fromJson(Map<String, dynamic> j) => ListingForm(
        rulesAccepted: j['rules_accepted'] == true,
        taxIdKind: _str(j['tax_id_kind'], 'iin'),
        taxIdOnFile: j['tax_id_on_file'] == true,
        maxPhotos: _int(j['max_photos'], 10),
        canPublish: j['can_publish'] != false,
        publishBlockReason: j['publish_block_reason'] as String?,
        defaultLocation: _str(j['default_location'], 'Караганда'),
        types: ((j['types'] as List?) ?? [])
            .map((e) => ListingTypeSpec(
                  _str(e['code']),
                  _str(e['label']),
                  e['has_price'] == true,
                  e['has_category'] == true,
                  e['has_quantity'] == true,
                  e['requires_exchange_for'] == true,
                ))
            .toList(),
      );
}

class MyListing {
  final ProductCard card;
  final String status;
  final bool editableInApp;
  final int views;

  MyListing(this.card, this.status, this.editableInApp, this.views);

  factory MyListing.fromJson(Map<String, dynamic> j) => MyListing(
        ProductCard.fromJson(j),
        _str(j['status']),
        j['editable_in_app'] == true,
        _int(j['view_count']),
      );
}

class ListingImage {
  final String file;
  final String? url;
  final bool isCover;
  ListingImage(this.file, this.url, this.isCover);
}

class EditableListing {
  final int id;
  final String type;
  final bool editableInApp;
  final String title;
  final String description;
  final String characteristics;
  final int price;
  final String? category;
  final String? exchangeFor;
  final int quantity;
  final String location;
  final String? whatsapp;
  final List<ListingImage> images;

  EditableListing({
    required this.id,
    required this.type,
    required this.editableInApp,
    required this.title,
    required this.description,
    required this.characteristics,
    required this.price,
    required this.category,
    required this.exchangeFor,
    required this.quantity,
    required this.location,
    required this.whatsapp,
    required this.images,
  });

  factory EditableListing.fromJson(Map<String, dynamic> j) => EditableListing(
        id: _int(j['id']),
        type: _str(j['type'], 'used'),
        editableInApp: j['editable_in_app'] == true,
        title: _str(j['title']),
        description: _str(j['description']),
        characteristics: _str(j['characteristics']),
        price: _int(j['price']),
        category: j['category'] as String?,
        exchangeFor: j['exchange_for'] as String?,
        quantity: _int(j['quantity'], 1),
        location: _str(j['location']),
        whatsapp: j['whatsapp'] as String?,
        images: ((j['images'] as List?) ?? [])
            .map((e) => ListingImage(_str(e['file']), e['url'] as String?, e['is_cover'] == true))
            .toList(),
      );
}

String listingStatusLabel(String s) => switch (s) {
      'active' => 'Активно',
      'moderation' || 'pending' => 'На модерации',
      'sold' => 'Продано',
      'reserved' => 'Забронировано',
      'archived' || 'hidden' => 'Скрыто',
      'rejected' => 'Отклонено',
      'draft' => 'Черновик',
      _ => s,
    };

// ---------------------------------------------------------------- сообщения

class ChatPeer {
  final int id;
  final String name;
  final String? avatar;
  ChatPeer(this.id, this.name, this.avatar);

  String get initial => name.trim().isEmpty ? '?' : String.fromCharCode(name.trim().runes.first).toUpperCase();

  factory ChatPeer.fromJson(Map<String, dynamic> j) =>
      ChatPeer(_int(j['id']), _str(j['name']), j['avatar_url'] as String?);
}

class ChatProduct {
  final int id;
  final String title;
  final String? image;
  ChatProduct(this.id, this.title, this.image);

  static ChatProduct? fromJson(Object? j) => j is Map<String, dynamic>
      ? ChatProduct(_int(j['id']), _str(j['title']), j['image_url'] as String?)
      : null;
}

class ChatSummary {
  final int id;
  final ChatPeer peer;
  final ChatProduct? product;
  final String? lastPreview;
  final String? lastMessageAt;
  final int unread;

  ChatSummary(this.id, this.peer, this.product, this.lastPreview, this.lastMessageAt, this.unread);

  factory ChatSummary.fromJson(Map<String, dynamic> j) => ChatSummary(
        _int(j['id']),
        ChatPeer.fromJson((j['peer'] as Map).cast<String, dynamic>()),
        ChatProduct.fromJson(j['product']),
        j['last_preview'] as String?,
        j['last_message_at'] as String?,
        _int(j['unread_count']),
      );
}

class ChatConversation {
  final int id;
  final ChatPeer peer;
  final ChatProduct? product;
  final int? orderId;

  ChatConversation(this.id, this.peer, this.product, this.orderId);

  factory ChatConversation.fromJson(Map<String, dynamic> j) => ChatConversation(
        _int(j['id']),
        ChatPeer.fromJson((j['peer'] as Map).cast<String, dynamic>()),
        ChatProduct.fromJson(j['product']),
        j['order_id'] == null ? null : _int(j['order_id']),
      );
}

class ChatMessage {
  final int id;
  final bool mine;
  final String body;
  final bool read;
  final String? createdAt;
  /// Локальное сообщение, ещё не подтверждённое сервером.
  final bool pending;

  ChatMessage(this.id, this.mine, this.body, this.read, this.createdAt, {this.pending = false});

  factory ChatMessage.fromJson(Map<String, dynamic> j) => ChatMessage(
        _int(j['id']),
        j['is_mine'] == true,
        _str(j['body']),
        j['is_read'] == true,
        j['created_at'] as String?,
      );
}

class ChatThread {
  final ChatConversation? conversation;
  final List<ChatMessage> messages;
  final int unreadTotal;
  ChatThread(this.conversation, this.messages, this.unreadTotal);
}

/// «12:40», «вчера», «14.11» — для списка диалогов.
String chatTime(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (d == null) return '';
  final now = DateTime.now();
  String two(int v) => v.toString().padLeft(2, '0');
  final today = DateTime(now.year, now.month, now.day);
  final day = DateTime(d.year, d.month, d.day);
  if (day == today) return '${two(d.hour)}:${two(d.minute)}';
  if (today.difference(day).inDays == 1) return 'вчера';
  return '${two(d.day)}.${two(d.month)}${d.year == now.year ? '' : '.${d.year % 100}'}';
}
