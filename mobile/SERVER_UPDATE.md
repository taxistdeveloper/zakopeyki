# Обновление сервера для приложения (сообщения, кошелёк, настройки, поддержка, продавец, аукцион)

Код — в ветке `feature/contests-module` (рабочая копия `C:\MAMP\htdocs\zakopeyki-contests`).
На zakopeyki.kz нужно выложить:

## 1. Новые файлы → `app/Controllers/Api/Mobile/`

- `ChatApiController.php` — сообщения
- `AccountApiController.php` — личные данные, пароль, 2FA, уведомления
- `WalletApiController.php` — кошелёк
- `SupportApiController.php` — поддержка
- `UserApiController.php` — профиль продавца, подписка, ставки на аукционе
- `CabinetApiController.php` — фото профиля, «о себе», отзывы обо мне, подписки, рефералы, бонусы
- `StoryApiController.php` — истории: лента, публикация фото/видео, удаление, ответ автору
- `StreamApiController.php` — прямые эфиры: список, просмотр, WebRTC-сигналы, чат, лайки, розыгрыш, запуск/завершение эфира с телефона
- `ContestApiController.php` — конкурсы и розыгрыши (участник): каталог, карточка, участие, выход, жалоба, подтверждение/спор по призу, «мои конкурсы»
- `BizApiController.php` — бизнес-аккаунт: заявка ИП/ТОО с документами, бизнес-пакет и доп. опции (оплата с кошелька)
- `AdApiController.php` — реклама: кампании, создание с баннером, оплата картой, пауза/возобновление, повторная отправка
- `PagesApiController.php` — тексты «О сервисе», оферты, политик и правил размещения (из языковых файлов сайта)
- `AssistantApiController.php` — ИИ-помощник ZAK (тот же движок, что `/ai/chat` на сайте; гость — по `X-Device-Id`)

### Изменённые файлы (P0: оплата заказа, отзыв)

- `app/Controllers/Api/Mobile/OrderApiController.php` — методы `pay`, `review`; в карточке заказа поля `can_review`, `my_review`; действие `pay` для `awaiting_payment`
- `lang/modules/ru/mobile_api.php`, `lang/modules/kk/mobile_api.php` — тексты `order_not_payable`, `payment_expired`
- `app/Services/Security/AuthThrottle.php` — лимиты `stream_like` (30/мин) и `stream_comment` (20/мин)
- `app/Services/AI/Core/PermissionChecker.php` — `roleFor()`: вошедший через приложение пользователь получает роль `user` (раньше проверялась только сессия сайта и приложение считалось гостем)

> Эфиры идут P2P (как на сайте), сервер передаёт только сигналы. Сейчас используются только STUN-серверы Google.
> У части мобильных операторов (строгий NAT) видео без TURN-сервера не дойдёт. TURN можно задать в `config/app.php`:
> `'webrtc_ice_servers' => [['urls' => 'stun:stun.l.google.com:19302'], ['urls' => 'turn:turn.example.kz:3478', 'username' => '…', 'credential' => '…']]` —
> приложение возьмёт его автоматически (сайт пока использует свой список в `public/assets/js/app.js`).

## 2. `config/routes.php`

Рядом с остальными `use App\Controllers\Api\Mobile\... as Mobile...Api;` добавить:

```php
use App\Controllers\Api\Mobile\ChatApiController as MobileChatApi;
use App\Controllers\Api\Mobile\AccountApiController as MobileAccountApi;
use App\Controllers\Api\Mobile\WalletApiController as MobileWalletApi;
use App\Controllers\Api\Mobile\SupportApiController as MobileSupportApi;
use App\Controllers\Api\Mobile\UserApiController as MobileUserApi;
use App\Controllers\Api\Mobile\CabinetApiController as MobileCabinetApi;
use App\Controllers\Api\Mobile\StoryApiController as MobileStoryApi;
use App\Controllers\Api\Mobile\StreamApiController as MobileStreamApi;
use App\Controllers\Api\Mobile\ContestApiController as MobileContestApi;
use App\Controllers\Api\Mobile\BizApiController as MobileBizApi;
use App\Controllers\Api\Mobile\AdApiController as MobileAdApi;
use App\Controllers\Api\Mobile\PagesApiController as MobilePagesApi;
use App\Controllers\Api\Mobile\AssistantApiController as MobileAssistantApi;
```

После строки `$router->post('/api/mobile/v1/orders/{id}/dispute', ...)` добавить:

```php
$router->post('/api/mobile/v1/orders/{id}/pay', [MobileOrderApi::class, 'pay']);
$router->post('/api/mobile/v1/orders/{id}/review', [MobileOrderApi::class, 'review']);
```

Перед строкой `$router->get('/api/mobile/v1/orders', ...)` добавить:

```php
// Аккаунт: личные данные, пароль, 2FA, уведомления
$router->get('/api/mobile/v1/me/profile', [MobileAccountApi::class, 'profile']);
$router->post('/api/mobile/v1/me/profile', [MobileAccountApi::class, 'updateProfile']);
$router->post('/api/mobile/v1/me/password', [MobileAccountApi::class, 'changePassword']);
$router->post('/api/mobile/v1/me/2fa/setup', [MobileAccountApi::class, 'twoFactorSetup']);
$router->post('/api/mobile/v1/me/2fa/confirm', [MobileAccountApi::class, 'twoFactorConfirm']);
$router->post('/api/mobile/v1/me/2fa/disable', [MobileAccountApi::class, 'twoFactorDisable']);
$router->get('/api/mobile/v1/me/notifications', [MobileAccountApi::class, 'notifications']);
$router->post('/api/mobile/v1/me/notifications/read', [MobileAccountApi::class, 'notificationsRead']);
$router->post('/api/mobile/v1/me/notifications/clear', [MobileAccountApi::class, 'notificationsClear']);
// Кошелёк
$router->get('/api/mobile/v1/wallet', [MobileWalletApi::class, 'index']);
$router->post('/api/mobile/v1/wallet/deposit', [MobileWalletApi::class, 'deposit']);
$router->post('/api/mobile/v1/wallet/withdraw', [MobileWalletApi::class, 'withdraw']);
// Поддержка
$router->get('/api/mobile/v1/support/tickets', [MobileSupportApi::class, 'index']);
$router->post('/api/mobile/v1/support/tickets', [MobileSupportApi::class, 'create']);
$router->get('/api/mobile/v1/support/tickets/{id}', [MobileSupportApi::class, 'show']);
$router->post('/api/mobile/v1/support/tickets/{id}/reply', [MobileSupportApi::class, 'reply']);
// Профиль продавца и аукционы
$router->get('/api/mobile/v1/users/{id}', [MobileUserApi::class, 'show']);
$router->post('/api/mobile/v1/users/{id}/follow', [MobileUserApi::class, 'follow']);
$router->get('/api/mobile/v1/auctions/{id}', [MobileUserApi::class, 'auction']);
$router->post('/api/mobile/v1/auctions/{id}/bid', [MobileUserApi::class, 'bid']);
$router->post('/api/mobile/v1/auctions/{id}/buy-now', [MobileUserApi::class, 'buyNow']);
// Личный кабинет: фото, о себе, отзывы, подписки, рефералы, бонусы
$router->post('/api/mobile/v1/me/avatar', [MobileCabinetApi::class, 'avatar']);
$router->post('/api/mobile/v1/me/bio', [MobileCabinetApi::class, 'bio']);
$router->get('/api/mobile/v1/me/reviews', [MobileCabinetApi::class, 'reviews']);
$router->get('/api/mobile/v1/me/follows', [MobileCabinetApi::class, 'follows']);
$router->get('/api/mobile/v1/me/referral', [MobileCabinetApi::class, 'referral']);
$router->get('/api/mobile/v1/me/bonuses', [MobileCabinetApi::class, 'bonuses']);
// Истории
$router->get('/api/mobile/v1/stories', [MobileStoryApi::class, 'index']);
$router->post('/api/mobile/v1/stories', [MobileStoryApi::class, 'create']);
$router->post('/api/mobile/v1/stories/{id}/delete', [MobileStoryApi::class, 'delete']);
$router->post('/api/mobile/v1/stories/{id}/reply', [MobileStoryApi::class, 'reply']);
// Прямые эфиры (статические пути — раньше /streams/{id})
$router->get('/api/mobile/v1/streams', [MobileStreamApi::class, 'index']);
$router->post('/api/mobile/v1/streams', [MobileStreamApi::class, 'start']);
$router->get('/api/mobile/v1/streams/my-products', [MobileStreamApi::class, 'myProducts']);
$router->get('/api/mobile/v1/streams/{id}', [MobileStreamApi::class, 'show']);
$router->post('/api/mobile/v1/streams/{id}/signal', [MobileStreamApi::class, 'signal']);
$router->get('/api/mobile/v1/streams/{id}/signals', [MobileStreamApi::class, 'signals']);
$router->get('/api/mobile/v1/streams/{id}/comments', [MobileStreamApi::class, 'comments']);
$router->post('/api/mobile/v1/streams/{id}/comments', [MobileStreamApi::class, 'comment']);
$router->post('/api/mobile/v1/streams/{id}/like', [MobileStreamApi::class, 'like']);
$router->post('/api/mobile/v1/streams/{id}/giveaway/join', [MobileStreamApi::class, 'joinGiveaway']);
$router->post('/api/mobile/v1/streams/{id}/giveaway/draw', [MobileStreamApi::class, 'drawGiveaway']);
$router->post('/api/mobile/v1/streams/{id}/heartbeat', [MobileStreamApi::class, 'heartbeat']);
$router->post('/api/mobile/v1/streams/{id}/end', [MobileStreamApi::class, 'end']);
$router->post('/api/mobile/v1/streams/{id}/feature', [MobileStreamApi::class, 'feature']);
// Конкурсы и розыгрыши (сторона участника)
$router->get('/api/mobile/v1/contests', [MobileContestApi::class, 'index']);
$router->get('/api/mobile/v1/me/contests', [MobileContestApi::class, 'mine']);
$router->get('/api/mobile/v1/contests/{slug}', [MobileContestApi::class, 'show']);
$router->post('/api/mobile/v1/contests/{slug}/join', [MobileContestApi::class, 'join']);
$router->post('/api/mobile/v1/contests/{slug}/withdraw', [MobileContestApi::class, 'withdraw']);
$router->post('/api/mobile/v1/contests/{slug}/report', [MobileContestApi::class, 'report']);
$router->post('/api/mobile/v1/contests/{slug}/prize/{winnerId}/confirm', [MobileContestApi::class, 'confirmPrize']);
$router->post('/api/mobile/v1/contests/{slug}/prize/{winnerId}/dispute', [MobileContestApi::class, 'disputePrize']);
// Бизнес-аккаунт и бизнес-пакет
$router->get('/api/mobile/v1/business', [MobileBizApi::class, 'index']);
$router->post('/api/mobile/v1/business/upgrade', [MobileBizApi::class, 'upgrade']);
$router->post('/api/mobile/v1/business/packages/{id}/buy', [MobileBizApi::class, 'buy']);
// Реклама
$router->get('/api/mobile/v1/ads', [MobileAdApi::class, 'index']);
$router->post('/api/mobile/v1/ads', [MobileAdApi::class, 'create']);
$router->post('/api/mobile/v1/ads/{id}/pay', [MobileAdApi::class, 'pay']);
$router->post('/api/mobile/v1/ads/{id}/pause', [MobileAdApi::class, 'pause']);
$router->post('/api/mobile/v1/ads/{id}/resume', [MobileAdApi::class, 'resume']);
$router->post('/api/mobile/v1/ads/{id}/resubmit', [MobileAdApi::class, 'resubmit']);
// Тексты: о сервисе, оферта, политики, правила размещения
$router->get('/api/mobile/v1/pages/{slug}', [MobilePagesApi::class, 'show']);
// ИИ-помощник ZAK
$router->get('/api/mobile/v1/assistant', [MobileAssistantApi::class, 'index']);
$router->get('/api/mobile/v1/assistant/messages', [MobileAssistantApi::class, 'poll']);
$router->post('/api/mobile/v1/assistant/messages', [MobileAssistantApi::class, 'sendMessage']);
$router->post('/api/mobile/v1/assistant/clear', [MobileAssistantApi::class, 'clear']);
$router->post('/api/mobile/v1/assistant/feedback', [MobileAssistantApi::class, 'feedback']);
// Сообщения (статические пути — раньше /chats/{id})
$router->get('/api/mobile/v1/chats', [MobileChatApi::class, 'index']);
$router->get('/api/mobile/v1/chats/unread', [MobileChatApi::class, 'unread']);
$router->post('/api/mobile/v1/chats/start', [MobileChatApi::class, 'start']);
$router->get('/api/mobile/v1/chats/{id}', [MobileChatApi::class, 'show']);
$router->post('/api/mobile/v1/chats/{id}/messages', [MobileChatApi::class, 'sendMessage']);
```

## 3. `.htaccess`

Уже исправлено на сервере вручную (передача `Authorization`). В ветке та же строка тоже есть.

## Проверка

```bash
php -l config/routes.php
curl -s https://zakopeyki.kz/api/mobile/v1/chats
```

Должно вернуть `{"success":false,"error":{"code":"auth_required",...}}` (а не `not_found`).
