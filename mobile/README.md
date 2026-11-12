# Zakopeyki.kz — мобильное приложение (Flutter)

Код приложения: [`app/`](app/). Backend — существующий PHP-сайт, API `/api/mobile/v1` (см. `app/Controllers/Api/Mobile/`).

## Стек

Flutter 3.47 / Dart 3.13 · Riverpod 3 (состояние) · go_router (навигация) · Dio (HTTP) · flutter_secure_storage (Keychain/Keystore) · cached_network_image · gen-l10n (ru / kk / en).

```
app/lib/
  app.dart                 роутер, тема, локализация
  core/api/                ApiClient (Bearer, авто-refresh, конверт ошибок), ApiException
  core/auth/               AuthController (вход, 2FA, восстановление сессии, выход)
  core/storage/            SecureStore (refresh-токен, device id, язык)
  core/locale/, widgets/   язык интерфейса, тема бренда, состояния ошибок
  features/catalog/        лента, поиск, фильтр по типу, карточка товара, избранное (действие)
  features/favorites/      экран избранного
  features/auth/           вход + 2FA
  features/profile/        профиль, язык, правовые документы, выход
  l10n/                    app_ru.arb (шаблон), app_kk.arb, app_en.arb
```

Правило: бизнес-логика (цены, комиссии, доступность покупки) — только на сервере; клиент отображает.

## Локальный запуск (iOS-симулятор)

```bash
# 1. backend (из корня репозитория)
php -S 127.0.0.1:8137 bin/dev_router.php
# 2. тестовый пользователь (только local; пароль — в самом скрипте)
php bin/seed_mobile_test_user.php
# 3. приложение
cd mobile/app && flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8137
```

Сайт в режиме заглушки (`stub_mode`) — каталог в приложении видят только admin и пользователи с `site_access` (тестовый пользователь его имеет). Для Android-эмулятора хост — `http://10.0.2.2:8137`; предварительно установить Android `cmdline-tools` (`flutter doctor`).

## Android

- `applicationId` `kz.zakopeyki.zakopeyki`, minSdk 23, targetSdk — по Flutter (API 36, требование Google Play с 31.08.2026).
- Сеть: `INTERNET` в основном манифесте; `network_security_config` — release только HTTPS, debug дополнительно разрешает HTTP к `10.0.2.2`/`127.0.0.1` (локальный backend из эмулятора). `allowBackup=false` (токены не попадают в резервные копии), predictive back включён.
- Иконка/сплэш: монета «10» с сайта, adaptive icon (`dart run flutter_launcher_icons`).
- Подпись release: `android/key.properties` (в .gitignore, образец — `android/key.properties.example`). Без него release подписывается debug-ключом — **такой файл нельзя загружать в Google Play**.

```bash
# локальный запуск в эмуляторе (backend на хосте)
flutter run -d emulator-5554 --dart-define=API_BASE_URL=http://10.0.2.2:8137
# сборка для Google Play
flutter build appbundle --release --dart-define=API_BASE_URL=https://zakopeyki.kz
```

На этом Mac (Intel i7-9750H, 16 ГБ) образ Android 17 (Play Store) зависает. Рабочий вариант — лёгкий AVD `Zakopeyki_API34` (Android 14 `google_apis` x86_64):
```bash
~/Library/Android/sdk/emulator/emulator -avd Zakopeyki_API34 -gpu host
```
Не запускайте одновременно эмулятор, сборку Gradle и iOS-симулятор — 16 ГБ не хватает.

## Тесты

```bash
cd mobile/app
flutter analyze
flutter test                                   # unit + widget (12)
flutter test integration_test -d <simulator> \
  --dart-define=API_BASE_URL=http://127.0.0.1:8137 \
  --dart-define=TEST_EMAIL=mobile.test@zakopeyki.local --dart-define=TEST_PASSWORD=<из seed-скрипта>
php ../../tools/run_mobile_api_tests.php       # backend: токены, презентер (20)
```

## Безопасность

- В приложении нет секретов backend; `API_BASE_URL` — единственная настройка сборки.
- Access-токен (15 мин) — только в памяти; refresh-токен (60 дн., ротация, обнаружение повторного использования) — в Keychain/Keystore.
- iOS: разрешён HTTP только для локальной сети (`NSAllowsLocalNetworking`) — интернет-трафик только HTTPS.
