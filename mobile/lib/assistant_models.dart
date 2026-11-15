// ИИ-помощник ZAK (контракт /api/mobile/v1/assistant).

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;

List<Map<String, dynamic>> _maps(Object? v) =>
    ((v as List?) ?? []).map((e) => (e as Map).cast<String, dynamic>()).toList();

class ZakSuggestion {
  final String label;
  final String message;
  ZakSuggestion(this.label, this.message);

  factory ZakSuggestion.fromJson(Map<String, dynamic> j) => ZakSuggestion(_str(j['label']), _str(j['message']));

  static List<ZakSuggestion> list(Object? v) => _maps(v).map(ZakSuggestion.fromJson).toList();
}

class ZakProduct {
  final int id;
  final String title;
  final String priceText;
  final String typeLabel;
  final String location;
  final String? image;
  ZakProduct(this.id, this.title, this.priceText, this.typeLabel, this.location, this.image);

  factory ZakProduct.fromJson(Map<String, dynamic> j) => ZakProduct(
        _int(j['id']),
        _str(j['title']),
        _str(j['price_text']),
        _str(j['type_label']),
        _str(j['location']),
        j['image'] as String?,
      );

  static List<ZakProduct> list(Object? v) => _maps(v).map(ZakProduct.fromJson).toList();
}

/// Сообщение диалога. from: user | ai | agent | system.
class ZakMessage {
  final int? id;
  final String from;
  final String text;
  final List<ZakProduct> products;
  final List<ZakSuggestion> suggestions;
  int? rating;

  ZakMessage({
    required this.from,
    required this.text,
    this.id,
    this.products = const [],
    this.suggestions = const [],
  });

  bool get mine => from == 'user';
  bool get byOperator => from == 'agent';

  factory ZakMessage.fromJson(Map<String, dynamic> j) => ZakMessage(
        id: _int(j['id']),
        from: _str(j['from'], 'ai'),
        text: _str(j['text']),
        products: ZakProduct.list(j['products']),
        suggestions: ZakSuggestion.list(j['suggestions']),
      );
}

class ZakInfo {
  final String title;
  final String status;
  final String welcome;
  final String placeholder;
  final List<ZakSuggestion> suggestions;
  final String conversationStatus;
  final List<ZakMessage> messages;

  ZakInfo(this.title, this.status, this.welcome, this.placeholder, this.suggestions, this.conversationStatus,
      this.messages);

  factory ZakInfo.fromJson(Map<String, dynamic> j) => ZakInfo(
        _str(j['title'], 'ZAK'),
        _str(j['status']),
        _str(j['welcome']),
        _str(j['placeholder'], 'Спросите ZAK…'),
        ZakSuggestion.list(j['suggestions']),
        _str((j['conversation'] as Map?)?['status'], 'ai_active'),
        _maps(j['messages']).map(ZakMessage.fromJson).toList(),
      );
}

class ZakReply {
  final String reply;
  final List<ZakProduct> products;
  final List<ZakSuggestion> suggestions;
  final String status;
  final int? messageId;
  final int? aiMessageId;
  final bool pending;

  ZakReply(this.reply, this.products, this.suggestions, this.status, this.messageId, this.aiMessageId, this.pending);

  factory ZakReply.fromJson(Map<String, dynamic> j) => ZakReply(
        _str(j['reply']),
        ZakProduct.list(j['products']),
        ZakSuggestion.list(j['suggestions']),
        _str(j['conversation_status'], 'ai_active'),
        j['message_id'] == null ? null : _int(j['message_id']),
        j['ai_message_id'] == null ? null : _int(j['ai_message_id']),
        j['pending'] == true,
      );
}
