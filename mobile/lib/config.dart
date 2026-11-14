/// Адрес сайта. Для локальной отладки:
///   flutter run --dart-define=SITE_URL=http://10.0.2.2/zakopeyki
const String kSiteUrl = String.fromEnvironment(
  'SITE_URL',
  defaultValue: 'https://zakopeyki.kz',
);

const String kApiPrefix = '/api/mobile/v1';

/// Cookie с токеном устройства для WebView (см. App\Core\MobileAuth на сервере).
const String kTokenCookie = 'zk_mt';

/// Маркер приложения в User-Agent WebView и заголовок для API.
const String kAppMarker = 'ZakopeykiApp/1.0';

Uri siteUri(String path) {
  final base = Uri.parse(kSiteUrl);
  final basePath = base.path.endsWith('/')
      ? base.path.substring(0, base.path.length - 1)
      : base.path;
  final p = path.startsWith('/') ? path : '/$path';
  final parsed = Uri.parse(p);
  return base.replace(
    path: '$basePath${parsed.path}',
    query: parsed.hasQuery ? parsed.query : null,
  );
}

/// Путь страницы сайта без базового префикса (/zakopeyki) — или null, если ссылка не на наш сайт.
String? sitePathOf(Uri uri) {
  final base = Uri.parse(kSiteUrl);
  if (uri.host != base.host) return null;
  if (base.hasPort && uri.port != base.port) return null;
  final basePath = base.path.endsWith('/')
      ? base.path.substring(0, base.path.length - 1)
      : base.path;
  var path = uri.path;
  if (basePath.isNotEmpty) {
    if (!path.startsWith(basePath)) return null;
    path = path.substring(basePath.length);
  }
  if (path.isEmpty) path = '/';
  if (path.length > 1 && path.endsWith('/')) {
    path = path.substring(0, path.length - 1);
  }
  return path;
}
