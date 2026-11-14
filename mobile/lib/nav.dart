import 'package:flutter/material.dart';

import 'main.dart';
import 'screens/login_screen.dart';
import 'screens/product_screen.dart';
import 'screens/web_screen.dart';

Future<void> openWeb(BuildContext context, String path, {String? title}) {
  return Navigator.of(context).push(MaterialPageRoute(
    builder: (_) => WebScreen(path: path, title: title),
  ));
}

Future<void> openProduct(BuildContext context, int id) {
  return Navigator.of(context).push(MaterialPageRoute(
    builder: (_) => ProductScreen(id: id),
  ));
}

/// Открывает вход. Возвращает true, если пользователь вошёл.
Future<bool> openLogin(BuildContext context) async {
  final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(
    fullscreenDialog: true,
    builder: (_) => const LoginScreen(),
  ));
  return ok == true;
}

/// Выполнить действие, требующее входа; если не вошёл — сначала экран входа.
Future<void> requireLogin(BuildContext context, Future<void> Function() action) async {
  final session = SessionScope.read(context);
  if (session.loggedIn) {
    await action();
    return;
  }
  final ok = await openLogin(context);
  if (ok && context.mounted) await action();
}

void showSnack(BuildContext context, String text) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(text), behavior: SnackBarBehavior.floating));
}
