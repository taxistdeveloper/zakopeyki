# Закопейки — Android-приложение (Flutter)

Гибридное приложение: нативные экраны там, где важна скорость, и сайт во встроенном WebView для сложных разделов.

| Нативно (Flutter + `/api/mobile/v1`) | WebView (сайт с той же сессией) |
|---|---|
| Каталог, поиск, фильтр по типу и категории | Корзина, оформление, оплата |
| Карточка товара, галерея, похожие | Сообщения (`/chat`) |
| Избранное | Подача и управление объявлениями |
| Вход по email + 2FA (Bearer + refresh) | Заказы, кошелёк, поддержка, настройки профиля |
| Экран «скоро открытие» (stub_mode) | Регистрация, восстановление пароля |

## Как работает сессия

Сервер — боевой мобильный API `/api/mobile/v1` (`app/Controllers/Api/Mobile/`, ветка `feature/contests-module`). Ответы `{success, data, meta?}` / `{success:false, error:{code, message, fields?}}`.

1. При первом запуске генерируется `device_id` (Keystore), он уходит в заголовке `X-Device-Id`.
2. Вход: `POST /auth/login` → `access_token` (15 мин, только в памяти) + `refresh_token` (60 дней, Keystore). При 2FA — `challenge_token` → `POST /auth/2fa`.
3. Запросы с `Authorization: Bearer`; на 401 клиент один раз делает `POST /auth/refresh` и повторяет запрос.
4. Пока сайт в режиме «скоро открытие», каталог (`site_closed`) доступен только admin и аккаунтам с `site_access`.
5. **WebView не делит сессию с приложением**: API работает только по Bearer, поэтому в разделах сайта (корзина, чаты, кабинет) сайт при первом заходе попросит войти; cookie WebView сохраняются. Регистрация и сброс пароля — на сайте, затем вход в приложении.

Ссылки `/product/{id}` внутри WebView открывают нативную карточку, `tel:`/`wa.me`/`t.me` — внешние приложения. `<input type="file">` на сайте открывает камеру/галерею.

## Вход через Google

Нативный Google Sign-In (`google_sign_in`) → ID-токен → `POST /api/mobile/v1/auth/google`. Токен выпускается для web-клиента сайта (`kGoogleServerClientId` в `lib/config.dart`).

Чтобы заработало, нужно один раз настроить:

1. **Google Cloud Console** (проект, где web-клиент сайта) → Credentials → Create OAuth client ID → **Android**:
   package `kz.zakopeyki.app`, SHA-1 ключа подписи. Для текущих сборок (debug-ключ этой машины):
   `50:70:18:AE:39:05:85:34:40:A6:65:AA:36:F5:C7:B4:1E:F6:DD:53`. При переходе на свой release-ключ / Google Play App Signing — добавить и их SHA-1.
2. **Сервер**: переменная окружения `GOOGLE_MOBILE_CLIENT_IDS=<web client id сайта>` (см. `config/mobile.php`). Пока она пустая, API отвечает `provider_disabled`.

## Запуск

```bash
flutter pub get
```

Против боевого сайта (по умолчанию `https://zakopeyki.kz`):

```bash
flutter run
```

Против локального MAMP из эмулятора Android:

```bash
flutter run --dart-define=SITE_URL=http://10.0.2.2/zakopeyki   # нужен локальный backend с веткой feature/contests-module
```

Сборка релиза (нужен ключ подписи, см. ниже):

```bash
flutter build appbundle --release
```

## Что ещё не сделано

- Ключ подписи релиза (`android/key.properties`) и иконка/сплэш — сейчас стандартные Flutter.
- Push-уведомления (FCM).
- Перенос в нативные экраны: корзина/оформление, заказы, подача объявления — для них в боевом API уже есть эндпоинты (`/cart`, `/checkout`, `/orders`, `/listings`).
- Нативная регистрация (`POST /auth/register`) и сброс пароля (`/auth/forgot-password`).
