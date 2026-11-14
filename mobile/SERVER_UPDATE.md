# Обновление сервера для приложения (сообщения, кошелёк, настройки, поддержка, продавец, аукцион)

Код — в ветке `feature/contests-module` (рабочая копия `C:\MAMP\htdocs\zakopeyki-contests`).
На zakopeyki.kz нужно выложить:

## 1. Новые файлы → `app/Controllers/Api/Mobile/`

- `ChatApiController.php` — сообщения
- `AccountApiController.php` — личные данные, пароль, 2FA, уведомления
- `WalletApiController.php` — кошелёк
- `SupportApiController.php` — поддержка
- `UserApiController.php` — профиль продавца, подписка, ставки на аукционе

### Изменённые файлы (P0: оплата заказа, отзыв)

- `app/Controllers/Api/Mobile/OrderApiController.php` — методы `pay`, `review`; в карточке заказа поля `can_review`, `my_review`; действие `pay` для `awaiting_payment`
- `lang/modules/ru/mobile_api.php`, `lang/modules/kk/mobile_api.php` — тексты `order_not_payable`, `payment_expired`

## 2. `config/routes.php`

Рядом с остальными `use App\Controllers\Api\Mobile\... as Mobile...Api;` добавить:

```php
use App\Controllers\Api\Mobile\ChatApiController as MobileChatApi;
use App\Controllers\Api\Mobile\AccountApiController as MobileAccountApi;
use App\Controllers\Api\Mobile\WalletApiController as MobileWalletApi;
use App\Controllers\Api\Mobile\SupportApiController as MobileSupportApi;
use App\Controllers\Api\Mobile\UserApiController as MobileUserApi;
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
