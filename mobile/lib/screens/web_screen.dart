import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';

import '../config.dart';
import '../main.dart';
import '../nav.dart';

/// Раздел сайта внутри приложения. Сессия поднимается по cookie zk_mt.
class WebScreen extends StatefulWidget {
  const WebScreen({super.key, required this.path, this.title, this.embedded = false});

  final String path;
  final String? title;

  /// Вкладка нижнего меню (без кнопки «назад» в AppBar).
  final bool embedded;

  @override
  State<WebScreen> createState() => _WebScreenState();
}

class _WebScreenState extends State<WebScreen> {
  late final WebViewController _web;
  int _progress = 0;
  String? _pageTitle;
  String? _error;
  bool _adopting = false;

  static final _productPath = RegExp(r'^/product/(\d+)$');
  static const _authPaths = {'/login', '/login/2fa', '/register', '/forgot-password', '/logout'};

  /// Ссылки, которые всегда открываем во внешних приложениях.
  static const _externalHosts = {
    'wa.me', 'api.whatsapp.com', 't.me', 'telegram.me', 'instagram.com', 'www.instagram.com',
    'play.google.com', 'apps.apple.com', 'youtube.com', 'www.youtube.com', 'youtu.be',
    '2gis.kz', 'go.2gis.com', 'yandex.kz', 'yandex.ru', 'maps.google.com',
  };

  @override
  void initState() {
    super.initState();
    _web = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(Colors.white)
      ..addJavaScriptChannel('ZkApp', onMessageReceived: _onJsMessage)
      ..setNavigationDelegate(NavigationDelegate(
        onNavigationRequest: _onNavigation,
        onProgress: (p) => setState(() => _progress = p),
        onPageStarted: (_) => setState(() => _error = null),
        onPageFinished: _onPageFinished,
        onWebResourceError: (e) {
          if (e.isForMainFrame ?? true) {
            setState(() => _error = 'Не удалось загрузить страницу');
          }
        },
      ));
    _init();
  }

  Future<void> _init() async {
    final ua = await _web.getUserAgent();
    await _web.setUserAgent('${ua ?? ''} $kAppMarker'.trim());

    final platform = _web.platform;
    if (platform is AndroidWebViewController) {
      await platform.setMediaPlaybackRequiresUserGesture(false);
      await platform.setOnShowFileSelector(_pickFiles);
    }
    await _web.loadRequest(siteUri(widget.path));
  }

  // --- навигация ----------------------------------------------------------

  Future<NavigationDecision> _onNavigation(NavigationRequest request) async {
    final uri = Uri.tryParse(request.url);
    if (uri == null) return NavigationDecision.prevent;

    // tel:, mailto:, whatsapp:, intent: …
    if (uri.scheme != 'http' && uri.scheme != 'https') {
      _openExternal(uri);
      return NavigationDecision.prevent;
    }
    if (_externalHosts.contains(uri.host)) {
      _openExternal(uri);
      return NavigationDecision.prevent;
    }

    final path = sitePathOf(uri);
    if (path == null) {
      // Платёжные шлюзы и прочие внешние страницы — остаются в WebView,
      // чтобы редирект обратно на сайт сохранил сессию.
      return NavigationDecision.navigate;
    }

    if (!request.isMainFrame) return NavigationDecision.navigate;

    // Карточка товара — нативный экран
    final m = _productPath.firstMatch(path);
    if (m != null && !uri.queryParameters.containsKey('full')) {
      openProduct(context, int.parse(m.group(1)!));
      return NavigationDecision.prevent;
    }

    // Вход по паролю — нативный экран
    if (path == '/login' && !uri.queryParameters.containsKey('code')) {
      final ok = await openLogin(context);
      if (ok && mounted) _web.reload();
      return NavigationDecision.prevent;
    }

    // Google блокирует OAuth во встроенных WebView
    if (path == '/auth/google') {
      showSnack(context, 'Вход через Google в приложении пока недоступен — войдите по email');
      return NavigationDecision.prevent;
    }

    return NavigationDecision.navigate;
  }

  Future<void> _onPageFinished(String url) async {
    final title = await _web.getTitle();
    if (!mounted) return;
    setState(() {
      _progress = 100;
      _pageTitle = title;
    });

    final session = SessionScope.read(context);
    final uri = Uri.tryParse(url);
    final path = uri == null ? null : sitePathOf(uri);
    if (path == null) return;

    if (session.loggedIn) {
      // Выход через меню сайта: сервер отозвал токен, синхронизируемся
      if (path == '/' || _authPaths.contains(path)) session.refreshUser();
      return;
    }

    // Пользователь зарегистрировался / вошёл на сайте — забираем токен устройства.
    // Запрос идёт изнутри страницы, поэтому httponly-cookie сессии уходит автоматически.
    if (_authPaths.contains(path) || _adopting) return;
    _adopting = true;
    final endpoint = jsonEncode(siteUri('$kApiPrefix/auth/session-token').toString());
    final device = jsonEncode(session.deviceName);
    await _web.runJavaScript('''
      fetch($endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'X-Zk-App': '$kAppMarker', 'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({device: $device})
      }).then(function (r) { return r.status === 200 ? r.text() : null; })
        .then(function (t) { if (t) ZkApp.postMessage(t); })
        .catch(function () {});
    ''').catchError((_) {});
    _adopting = false;
  }

  Future<void> _onJsMessage(JavaScriptMessage message) async {
    try {
      final data = jsonDecode(message.message);
      if (data is Map<String, dynamic> && data['ok'] == true && data['token'] != null) {
        await SessionScope.read(context).adoptWebLogin(data);
        if (mounted) showSnack(context, 'Вы вошли в аккаунт');
      }
    } catch (_) {}
  }

  Future<void> _openExternal(Uri uri) async {
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && mounted) showSnack(context, 'Не удалось открыть ссылку');
  }

  // --- загрузка файлов (<input type="file">) ------------------------------

  Future<List<String>> _pickFiles(FileSelectorParams params) async {
    final picker = ImagePicker();
    final acceptsImages = params.acceptTypes.isEmpty ||
        params.acceptTypes.any((t) => t.isEmpty || t.startsWith('image') || t == '*/*');
    final multiple = params.mode == FileSelectorMode.openMultiple;

    if (!acceptsImages) {
      showSnack(context, 'Этот тип файлов можно загрузить только на сайте');
      return [];
    }

    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Сделать фото'),
              onTap: () => Navigator.pop(ctx, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Выбрать из галереи'),
              onTap: () => Navigator.pop(ctx, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source == null) return [];

    final List<XFile> files;
    if (source == ImageSource.gallery && multiple) {
      files = await picker.pickMultiImage(imageQuality: 85, maxWidth: 2560);
    } else {
      final f = await picker.pickImage(source: source, imageQuality: 85, maxWidth: 2560);
      files = f == null ? [] : [f];
    }
    return files.map((f) => Uri.file(f.path).toString()).toList();
  }

  // --- UI -----------------------------------------------------------------

  Future<void> _handleBack() async {
    if (await _web.canGoBack()) {
      await _web.goBack();
    } else if (!widget.embedded && mounted) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _handleBack();
      },
      child: Scaffold(
        appBar: AppBar(
          automaticallyImplyLeading: false,
          leading: widget.embedded
              ? null
              : IconButton(icon: const Icon(Icons.arrow_back_rounded), onPressed: _handleBack),
          title: Text(
            _pageTitle?.replaceAll(RegExp(r'\s*[—|-]\s*zakopeyki.*$', caseSensitive: false), '') ??
                widget.title ??
                '',
            overflow: TextOverflow.ellipsis,
          ),
          actions: [
            IconButton(onPressed: () => _web.reload(), icon: const Icon(Icons.refresh_rounded)),
          ],
          bottom: _progress < 100
              ? PreferredSize(
                  preferredSize: const Size.fromHeight(2),
                  child: LinearProgressIndicator(value: _progress / 100, minHeight: 2),
                )
              : null,
        ),
        body: _error != null
            ? Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.wifi_off_rounded, size: 48, color: Colors.black38),
                    const SizedBox(height: 12),
                    Text(_error!),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: () {
                        setState(() => _error = null);
                        _web.reload();
                      },
                      child: const Text('Повторить'),
                    ),
                  ],
                ),
              )
            : WebViewWidget(controller: _web),
      ),
    );
  }
}
