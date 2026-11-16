import 'package:flutter/material.dart';

import '../config.dart';
import '../nav.dart';
import 'ads_screen.dart';
import 'business_screen.dart';
import 'chat_screen.dart';
import 'chats_screen.dart';
import 'contests_screen.dart';
import 'info_page_screen.dart';
import 'my_listings_screen.dart';
import 'order_screen.dart';
import 'orders_screen.dart';
import 'seller_screen.dart';
import 'support_screens.dart';
import 'wallet_screen.dart';

/// Открывает ссылку сайта (из уведомлений и т.п.) нативным экраном приложения.
/// Если такого экрана нет — страницей сайта внутри приложения.
Future<void> openSiteLink(BuildContext context, String link) {
  final uri = Uri.tryParse(link);
  if (uri == null) return Future.value();
  final path = uri.hasScheme ? (sitePathOf(uri) ?? '') : _strip(uri.path);
  final screen = screenForSitePath(path, uri.queryParameters);
  if (screen != null) {
    return Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }
  final productId = RegExp(r'^/product/(\d+)$').firstMatch(path);
  if (productId != null) return openProduct(context, int.parse(productId.group(1)!));
  return openWeb(context, uri.hasQuery ? '$path?${uri.query}' : path);
}

/// Нативный экран для пути сайта или null.
Widget? screenForSitePath(String path, Map<String, String> query) {
  int? idOf(String prefix) {
    final m = RegExp('^$prefix/(\\d+)\$').firstMatch(path);
    return m == null ? null : int.parse(m.group(1)!);
  }

  if (path == '/chat') return const ChatsScreen();
  if (idOf('/chat') case final id?) return ChatScreen(chatId: id);
  if (path == '/orders') return const OrdersScreen();
  if (idOf('/orders') case final id?) return OrderScreen(id: id);
  if (path == '/wallet') return const WalletScreen();
  if (path == '/support') return const SupportListScreen();
  if (idOf('/support') case final id?) return SupportTicketScreen(id: id);
  if (idOf('/users') case final id?) return SellerScreen(id: id);
  if (path == '/profile' && query['tab'] == 'lots') return const MyListingsScreen();
  if (path == '/contests' || path == '/contests/my') return const ContestsScreen();
  final contest = RegExp(r'^/contests/([^/]+)$').firstMatch(path);
  if (contest != null) return ContestScreen(slug: Uri.decodeComponent(contest.group(1)!));
  if (path == '/business/package' || path == '/business/upgrade') return const BusinessScreen();
  if (path == '/ads') return const AdsScreen();
  const pages = {'/about': 'about', '/offer': 'offer', '/privacy': 'privacy', '/data-policy': 'data-policy'};
  if (pages[path] case final slug?) return InfoPageScreen(slug: slug);
  return null;
}

/// Убирает базовый префикс сайта (/zakopeyki при локальной отладке) из относительной ссылки.
String _strip(String path) {
  final base = Uri.parse(kSiteUrl).path.replaceAll(RegExp(r'/$'), '');
  if (base.isNotEmpty && path.startsWith(base)) path = path.substring(base.length);
  return path.isEmpty ? '/' : path;
}
