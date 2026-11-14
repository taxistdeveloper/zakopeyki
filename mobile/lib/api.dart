import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'config.dart';
import 'models.dart';
import 'account_models.dart';
import 'shop_models.dart';

class ApiException implements Exception {
  final int status;
  final String code;
  final String message;
  final Map<String, String> fields;

  ApiException(this.status, this.code, this.message, [this.fields = const {}]);

  /// Сайт в режиме «скоро открытие», у аккаунта нет раннего доступа.
  bool get isStub => code == 'site_closed';
  bool get isUnauthorized => status == 401;
  bool get isNetwork => status == 0;

  @override
  String toString() => message;
}

/// Пара токенов + пользователь (ответ login / 2fa / refresh / register).
class AuthSession {
  final String accessToken;
  final String refreshToken;
  final AppUser user;

  AuthSession(this.accessToken, this.refreshToken, this.user);
}

/// Результат входа: либо сессия, либо нужен 2FA-код.
class LoginResult {
  final AuthSession? session;
  final String? challenge;

  LoginResult({this.session, this.challenge});

  bool get needsTwoFactor => challenge != null;
}

/// Клиент /api/mobile/v1. Ответы: {success, data, meta?} / {success:false, error:{code,message,fields?}}.
class Api {
  Api({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  /// Access-токен (15 мин) — только в памяти.
  String? accessToken;

  /// Идентификатор установки, обязателен для входа и refresh.
  String deviceId = '';

  /// Вызывается при 401 на запросе с токеном; должен обновить accessToken. true — повторить запрос.
  Future<bool> Function()? onUnauthorized;

  static const _timeout = Duration(seconds: 25);

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Accept-Language': 'ru',
        'X-Device-Id': deviceId,
        'X-Platform': 'android',
        'X-App-Version': kAppVersion,
        if (accessToken != null) 'Authorization': 'Bearer $accessToken',
      };

  Future<_Envelope> _get(String path, [Map<String, String>? query]) {
    var uri = siteUri('$kApiPrefix$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: query);
    }
    return _send(() => _client.get(uri, headers: _headers));
  }

  Future<_Envelope> _post(String path, [Map<String, dynamic>? body, bool retryAuth = true]) {
    final uri = siteUri('$kApiPrefix$path');
    return _send(
      () => _client.post(
        uri,
        headers: {..._headers, 'Content-Type': 'application/json'},
        body: jsonEncode(body ?? const {}),
      ),
      retryAuth: retryAuth,
    );
  }

  Future<_Envelope> _multipart(String path, Map<String, String> fields, List<String> keep, List<String> files) {
    final uri = siteUri('$kApiPrefix$path');
    return _send(() async {
      final req = http.MultipartRequest('POST', uri)
        ..headers.addAll(_headers)
        ..fields.addAll(fields);
      for (var i = 0; i < keep.length; i++) {
        req.fields['keep_images[$i]'] = keep[i];
      }
      for (final f in files) {
        req.files.add(await http.MultipartFile.fromPath('images[]', f));
      }
      return http.Response.fromStream(await _client.send(req));
    }, timeout: const Duration(seconds: 120));
  }

  Future<_Envelope> _send(Future<http.Response> Function() call,
      {bool retryAuth = true, Duration timeout = _timeout}) async {
    final hadToken = accessToken != null;
    try {
      return await _sendOnce(call, timeout);
    } on ApiException catch (e) {
      final refresher = onUnauthorized;
      if (e.isUnauthorized && hadToken && retryAuth && refresher != null && await refresher()) {
        return _sendOnce(call, timeout);
      }
      rethrow;
    }
  }

  Future<_Envelope> _sendOnce(Future<http.Response> Function() call, Duration timeout) async {
    http.Response res;
    try {
      res = await call().timeout(timeout);
    } on SocketException {
      throw ApiException(0, 'network', 'Нет соединения с интернетом');
    } on TimeoutException {
      throw ApiException(0, 'network', 'Сервер не отвечает, попробуйте позже');
    } on http.ClientException {
      throw ApiException(0, 'network', 'Нет соединения с сервером');
    }

    Map<String, dynamic> body;
    try {
      body = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    } catch (_) {
      if (res.statusCode < 400) {
        // Вместо JSON пришла HTML-страница: на сервере нет /api/mobile/v1.
        throw ApiException(res.statusCode, 'no_api', 'Сервер пока не поддерживает приложение. Попробуйте позже.');
      }
      throw ApiException(res.statusCode, 'bad_response', 'Ошибка сервера (${res.statusCode})');
    }

    if (res.statusCode >= 400 || body['success'] != true) {
      final err = (body['error'] as Map?)?.cast<String, dynamic>() ?? const {};
      final code = err['code']?.toString() ?? 'error';
      final fields = ((err['fields'] as Map?) ?? const {})
          .map((k, v) => MapEntry(k.toString(), v.toString()));
      final message = err['message']?.toString();
      throw ApiException(
        res.statusCode,
        code,
        message != null && message.isNotEmpty ? message : 'Ошибка сервера (${res.statusCode})',
        fields,
      );
    }
    final data = body['data'];
    return _Envelope(
      data is Map<String, dynamic> ? data : const {},
      (body['meta'] as Map?)?.cast<String, dynamic>() ?? const {},
    );
  }

  // --- auth --------------------------------------------------------------

  Future<LoginResult> login(String email, String password, String deviceName) async {
    final e = await _post('/auth/login', {
      'email': email,
      'password': password,
      'device_id': deviceId,
      'device_name': deviceName,
      'platform': 'android',
    }, false);
    return _loginResult(e.data);
  }

  Future<LoginResult> twoFactor(String challenge, String code) async {
    final e = await _post('/auth/2fa', {
      'challenge_token': challenge,
      'code': code,
      'device_id': deviceId,
    }, false);
    return _loginResult(e.data);
  }

  /// Вход / регистрация через Google: ID-токен из нативного Google Sign-In.
  Future<LoginResult> google(String idToken, String deviceName) async {
    final e = await _post('/auth/google', {
      'id_token': idToken,
      'device_id': deviceId,
      'device_name': deviceName,
      'platform': 'android',
    }, false);
    return _loginResult(e.data);
  }

  Future<AuthSession> refresh(String refreshToken, String deviceName) async {
    final e = await _post('/auth/refresh', {
      'refresh_token': refreshToken,
      'device_id': deviceId,
      'device_name': deviceName,
      'platform': 'android',
    }, false);
    return _session(e.data);
  }

  Future<void> logout(String? refreshToken) async {
    await _post('/auth/logout', {'refresh_token': ?refreshToken}, false);
  }

  LoginResult _loginResult(Map<String, dynamic> d) {
    if (d['two_factor_required'] == true) {
      return LoginResult(challenge: d['challenge_token'] as String);
    }
    return LoginResult(session: _session(d));
  }

  AuthSession _session(Map<String, dynamic> d) => AuthSession(
        d['access_token'] as String,
        d['refresh_token'] as String,
        AppUser.fromJson(d['user'] as Map<String, dynamic>),
      );

  Future<AppUser> me() async {
    final e = await _get('/me');
    return AppUser.fromJson(e.data['user'] as Map<String, dynamic>);
  }

  // --- catalog -----------------------------------------------------------

  Future<AppConfig> config() async => AppConfig.fromJson((await _get('/config')).data);

  Future<List<Category>> categories() async {
    final e = await _get('/categories');
    return ((e.data['categories'] as List?) ?? [])
        .map((c) => Category.fromJson(c as Map<String, dynamic>))
        .toList();
  }

  Future<ProductPage> products({
    String? type,
    String? query,
    String? category,
    String? cursor,
    int limit = 20,
  }) async {
    final e = await _get('/products', {
      'type': ?type,
      if (query != null && query.length >= 2) 'q': query,
      if (category != null && category.isNotEmpty) 'category': category,
      'cursor': ?cursor,
      'limit': '$limit',
    });
    return ProductPage(
      items: _cards(e.data['items']),
      nextCursor: e.meta['next_cursor'] as String?,
    );
  }

  Future<ProductDetail> product(int id) async => ProductDetail.fromJson((await _get('/products/$id')).data);

  Future<List<ProductCard>> favorites() async => _cards((await _get('/me/favorites')).data['items']);

  Future<bool> toggleFavorite(int id) async {
    final e = await _post('/products/$id/favorite');
    return e.data['is_favorite'] == true;
  }

  // --- регистрация -------------------------------------------------------

  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    String? phone,
    required String deviceName,
  }) async {
    final e = await _post('/auth/register', {
      'name': name,
      'email': email,
      'password': password,
      if (phone != null && phone.isNotEmpty) 'phone': phone,
      'accept_offer': true,
      'device_id': deviceId,
      'device_name': deviceName,
      'platform': 'android',
    }, false);
    return _session(e.data);
  }

  Future<String> forgotPassword(String email) async {
    final e = await _post('/auth/forgot-password', {'email': email}, false);
    return e.data['message']?.toString() ?? 'Письмо отправлено';
  }

  // --- корзина и оформление ------------------------------------------------

  Future<CartData> cart() async => CartData.fromJson((await _get('/cart')).data);

  Future<CartData> addToCart(int productId, [int qty = 1]) async =>
      CartData.fromJson((await _post('/cart/items', {'product_id': productId, 'qty': qty})).data);

  Future<CartData> setCartQty(int productId, int qty) async =>
      CartData.fromJson((await _post('/cart/items/$productId/qty', {'qty': qty})).data);

  Future<CartData> removeFromCart(int productId) async =>
      CartData.fromJson((await _post('/cart/items/$productId/remove')).data);

  Future<CheckoutOptions> checkoutOptions({int? productId, int qty = 1}) async {
    final e = await _get('/checkout/options',
        productId == null ? {'source': 'cart'} : {'product_id': '$productId', 'qty': '$qty'});
    return CheckoutOptions.fromJson(e.data);
  }

  Future<CheckoutResult> checkout({
    int? productId,
    int qty = 1,
    required String dealMode,
    required String delivery,
    required String payment,
  }) async {
    final e = await _post('/checkout', {
      if (productId == null) 'source': 'cart' else 'product_id': productId,
      'qty': qty,
      'deal_mode': dealMode,
      'delivery_method': delivery,
      'payment_method': payment,
    });
    return CheckoutResult.fromJson(e.data);
  }

  // --- заказы ------------------------------------------------------------

  Future<List<Order>> orders(String role) async {
    final e = await _get('/orders', {'role': role});
    return ((e.data['items'] as List?) ?? []).map((o) => Order.fromJson(o as Map<String, dynamic>)).toList();
  }

  Future<Order> order(int id) async => Order.fromJson((await _get('/orders/$id')).data['order'] as Map<String, dynamic>);

  Future<Order> orderAction(int id, String action, [Map<String, dynamic>? body]) async =>
      Order.fromJson((await _post('/orders/$id/$action', body)).data['order'] as Map<String, dynamic>);

  /// Ссылка на форму оплаты картой для заказа в статусе awaiting_payment.
  Future<String> payOrder(int id) async => (await _post('/orders/$id/pay')).data['payment_url'] as String;

  Future<Order> reviewOrder(int id, int rating, String body) async =>
      Order.fromJson((await _post('/orders/$id/review', {'rating': rating, 'body': body})).data['order'] as Map<String, dynamic>);

  // --- удаление аккаунта, подтверждение email -------------------------------

  Future<Map<String, dynamic>> deletionStatus() async => (await _get('/me/deletion')).data;

  Future<void> deleteAccount({String? password}) async {
    await _post('/me/delete', {'confirm': true, 'password': ?password});
  }

  /// Повторно отправить письмо подтверждения. Возвращает текст для пользователя.
  Future<String> resendVerification() async {
    final e = await _post('/me/email/resend-verification');
    if (e.data['already_verified'] == true) return 'Email уже подтверждён';
    return e.data['message']?.toString() ?? 'Письмо отправлено';
  }

  // --- объявления --------------------------------------------------------

  Future<ListingForm> listingForm() async => ListingForm.fromJson((await _get('/listings/form')).data);

  Future<List<MyListing>> myListings() async => (((await _get('/me/listings')).data['items'] as List?) ?? [])
      .map((e) => MyListing.fromJson(e as Map<String, dynamic>))
      .toList();

  Future<EditableListing> editableListing(int id) async =>
      EditableListing.fromJson((await _get('/me/listings/$id')).data['listing'] as Map<String, dynamic>);

  /// Создание (id == null) или правка объявления. [files] — локальные пути новых фото.
  Future<int> saveListing({
    int? id,
    required Map<String, String> fields,
    List<String> keepImages = const [],
    List<String> files = const [],
  }) async {
    final e = await _multipart(id == null ? '/listings' : '/listings/$id', fields, keepImages, files);
    return (e.data['product_id'] as num?)?.toInt() ?? id ?? 0;
  }

  Future<void> deleteListing(int id) async {
    await _post('/listings/$id/delete');
  }

  // --- сообщения ----------------------------------------------------------

  Future<(List<ChatSummary>, int)> chats() async {
    final e = await _get('/chats');
    final items = ((e.data['items'] as List?) ?? [])
        .map((c) => ChatSummary.fromJson(c as Map<String, dynamic>))
        .toList();
    return (items, (e.data['unread_total'] as num?)?.toInt() ?? 0);
  }

  Future<int> chatUnread() async => ((await _get('/chats/unread')).data['unread_total'] as num?)?.toInt() ?? 0;

  /// Переписка; [after] > 0 — только новые сообщения (опрос).
  Future<ChatThread> chat(int id, {int after = 0}) async {
    final e = await _get('/chats/$id', after > 0 ? {'after': '$after'} : null);
    final conv = e.data['conversation'];
    return ChatThread(
      conv is Map<String, dynamic> ? ChatConversation.fromJson(conv) : null,
      ((e.data['messages'] as List?) ?? []).map((m) => ChatMessage.fromJson(m as Map<String, dynamic>)).toList(),
      (e.data['unread_total'] as num?)?.toInt() ?? 0,
    );
  }

  Future<ChatMessage> sendMessage(int chatId, String body) async =>
      ChatMessage.fromJson((await _post('/chats/$chatId/messages', {'body': body})).data['message'] as Map<String, dynamic>);

  /// Найти или создать диалог по товару или заказу.
  Future<ChatConversation> startChat({int? productId, int? orderId}) async {
    final e = await _post('/chats/start', {'product_id': ?productId, 'order_id': ?orderId});
    return ChatConversation.fromJson(e.data['conversation'] as Map<String, dynamic>);
  }

  // --- аккаунт ------------------------------------------------------------

  Future<AccountProfile> accountProfile() async =>
      AccountProfile.fromJson((await _get('/me/profile')).data['profile'] as Map<String, dynamic>);

  Future<(AccountProfile, AppUser)> updateAccountProfile(Map<String, String> fields) async {
    final e = await _post('/me/profile', fields);
    return (
      AccountProfile.fromJson(e.data['profile'] as Map<String, dynamic>),
      AppUser.fromJson(e.data['user'] as Map<String, dynamic>),
    );
  }

  Future<String> changePassword(String? current, String password) async {
    final e = await _post('/me/password', {'current_password': ?current, 'password': password});
    return e.data['message']?.toString() ?? 'Пароль изменён';
  }

  Future<(String, String)> twoFactorSetup() async {
    final e = await _post('/me/2fa/setup');
    return (e.data['secret'] as String, e.data['otpauth_uri'] as String);
  }

  Future<List<String>> twoFactorConfirm(String secret, String code) async {
    final e = await _post('/me/2fa/confirm', {'secret': secret, 'code': code});
    return ((e.data['recovery_codes'] as List?) ?? []).map((c) => c.toString()).toList();
  }

  Future<void> twoFactorDisable(String? password, String code) async {
    await _post('/me/2fa/disable', {'password': ?password, 'code': code});
  }

  Future<(List<AppNotification>, int)> notifications() async {
    final e = await _get('/me/notifications');
    return (
      ((e.data['items'] as List?) ?? []).map((n) => AppNotification.fromJson(n as Map<String, dynamic>)).toList(),
      (e.data['unread'] as num?)?.toInt() ?? 0,
    );
  }

  Future<void> notificationsRead() async {
    await _post('/me/notifications/read');
  }

  Future<void> notificationsClear() async {
    await _post('/me/notifications/clear');
  }

  // --- кошелёк -------------------------------------------------------------

  Future<WalletData> wallet() async => WalletData.fromJson((await _get('/wallet')).data);

  /// Пополнение: null — зачислено сразу, иначе ссылка на форму оплаты картой.
  Future<String?> walletDeposit(int amount) async {
    final e = await _post('/wallet/deposit', {'amount': amount});
    return e.data['status'] == 'pending' ? e.data['payment_url'] as String? : null;
  }

  Future<void> walletWithdraw(int amount, String dest, {String? taxId}) async {
    await _post('/wallet/withdraw', {'amount': amount, 'dest': dest, 'iin': ?taxId});
  }

  // --- поддержка -----------------------------------------------------------

  Future<(List<SupportTicket>, List<SupportCategory>)> supportTickets() async {
    final e = await _get('/support/tickets');
    return (
      ((e.data['items'] as List?) ?? []).map((t) => SupportTicket.fromJson(t as Map<String, dynamic>)).toList(),
      ((e.data['categories'] as List?) ?? [])
          .map((c) => SupportCategory(c['code'].toString(), c['label'].toString()))
          .toList(),
    );
  }

  Future<(SupportTicket, List<SupportMessage>)> supportTicket(int id) async =>
      _ticket(await _get('/support/tickets/$id'));

  Future<SupportTicket> createSupportTicket(String subject, String body, String category) async {
    final e = await _post('/support/tickets', {'subject': subject, 'body': body, 'category': category});
    return SupportTicket.fromJson(e.data['ticket'] as Map<String, dynamic>);
  }

  Future<(SupportTicket, List<SupportMessage>)> replySupport(int id, String body) async =>
      _ticket(await _post('/support/tickets/$id/reply', {'body': body}));

  (SupportTicket, List<SupportMessage>) _ticket(_Envelope e) => (
        SupportTicket.fromJson(e.data['ticket'] as Map<String, dynamic>),
        ((e.data['messages'] as List?) ?? []).map((m) => SupportMessage.fromJson(m as Map<String, dynamic>)).toList(),
      );

  // --- продавец, аукцион ----------------------------------------------------

  Future<SellerProfile> seller(int id) async =>
      SellerProfile.fromJson((await _get('/users/$id')).data['user'] as Map<String, dynamic>);

  Future<(bool, int)> toggleFollow(int id) async {
    final e = await _post('/users/$id/follow');
    return (e.data['is_following'] == true, (e.data['followers_count'] as num?)?.toInt() ?? 0);
  }

  Future<AuctionLive> auction(int id) async =>
      AuctionLive.fromJson((await _get('/auctions/$id')).data['auction'] as Map<String, dynamic>);

  Future<(AuctionLive, String)> placeBid(int id, int amount) async {
    final e = await _post('/auctions/$id/bid', {'amount': amount});
    return (AuctionLive.fromJson(e.data['auction'] as Map<String, dynamic>), e.data['message']?.toString() ?? 'Ставка принята');
  }

  Future<(AuctionLive, String)> auctionBuyNow(int id) async {
    final e = await _post('/auctions/$id/buy-now');
    return (AuctionLive.fromJson(e.data['auction'] as Map<String, dynamic>), e.data['message']?.toString() ?? 'Лот выкуплен');
  }

  List<ProductCard> _cards(Object? list) => ((list as List?) ?? [])
      .map((e) => ProductCard.fromJson(e as Map<String, dynamic>))
      .toList();
}

class _Envelope {
  final Map<String, dynamic> data;
  final Map<String, dynamic> meta;
  _Envelope(this.data, this.meta);
}
