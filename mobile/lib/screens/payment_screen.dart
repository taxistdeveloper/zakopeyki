import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../config.dart';

/// Платёжная форма банка (Halyk ePay). Это единственная «веб-страница» оформления:
/// карту вводят на стороне банка. По завершении сайт уводит на
/// zakopeyki://payment-result?status=… (или /payments/app-return) — закрываем экран с результатом.
///
/// Результат: 'success' | 'failure' | 'expired' | null (пользователь закрыл окно).
class PaymentScreen extends StatefulWidget {
  const PaymentScreen({super.key, required this.url});

  final String url;

  @override
  State<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends State<PaymentScreen> {
  late final WebViewController _web;
  int _progress = 0;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _web = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(Colors.white)
      ..setNavigationDelegate(NavigationDelegate(
        onProgress: (p) => setState(() => _progress = p),
        onNavigationRequest: (req) {
          final uri = Uri.tryParse(req.url);
          if (uri == null) return NavigationDecision.prevent;
          if (uri.scheme == 'zakopeyki') {
            _finish(uri.queryParameters['status']);
            return NavigationDecision.prevent;
          }
          if (sitePathOf(uri) == '/payments/app-return') {
            _finish(uri.queryParameters['result']);
            return NavigationDecision.prevent;
          }
          return NavigationDecision.navigate;
        },
      ))
      ..loadRequest(Uri.parse(widget.url));
  }

  void _finish(String? status) {
    if (_done) return;
    _done = true;
    Navigator.of(context).pop(status ?? 'failure');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Оплата картой'),
        leading: IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => Navigator.pop(context)),
        bottom: _progress < 100
            ? PreferredSize(
                preferredSize: const Size.fromHeight(2),
                child: LinearProgressIndicator(value: _progress / 100, minHeight: 2),
              )
            : null,
      ),
      body: WebViewWidget(controller: _web),
    );
  }
}
