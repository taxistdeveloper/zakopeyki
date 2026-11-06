# 05. ТЕХНИЧЕСКИЕ ЗАДАНИЯ НА ИСПРАВЛЕНИЕ ДЛЯ CURSOR

**Дата формирования:** 2026-11-02  
**Версия:** 1.0  
**Отдел:** Independent QA Department Zakopeyki.kz

---

## ПОРЯДОК ВНЕДРЕНИЯ ИСПРАВЛЕНИЙ

1. **Блок 1 (Критический):** QA-0003 (P0 — Предотвращение каскадного удаления заказов при удалении лота).
2. **Блок 2 (Финансовый):** QA-0001 (P1 — Взимание 10% комиссии с продавца) и QA-0002 (P1 — Окно споров 24ч).
3. **Блок 3 (Платежи):** QA-0004 (P1 — Эквайринг для кошелька).
4. **Блок 4 (Интеграции & API):** QA-0007 (P2 — CSRF для API микрозадач) и QA-0006 (P2 — Лимиты на лайки стримов).
5. **Блок 5 (Функциональный):** QA-0005 (P2 — Розыгрыш 500 сердец) и QA-0009 / QA-0008 (P3/P4 — Техдолг).

---

### ТЗ-001: Устранение каскадного удаления заказов и блокировка удаления активных лотов (QA-0003)

* **ID дефекта:** QA-0003
* **Приоритет:** P0 (Критический)
* **Контекст:** Продавец может удалить лот через `ProfileController::deleteLot()`, даже если по нему есть оплаченные заказы в эскроу-холде. Внешний ключ `ON DELETE CASCADE` физически стирает заказы из таблицы `orders`.
* **Затронутые файлы:**
  * `app/Controllers/ProfileController.php`
  * `app/Models/Order.php`
  * `app/Models/Product.php`
  * `database/migrate_fix_order_cascade.sql` (новая миграция)
* **Необходимые изменения:**
  1. В `app/Models/Order.php` добавить метод:
     ```php
     public function hasActiveOrdersForProduct(int $productId): bool
     {
         $stmt = $this->db->prepare(
             "SELECT COUNT(*) FROM orders 
              WHERE product_id = ? 
              AND status NOT IN ('completed', 'cancelled', 'refunded')"
         );
         $stmt->execute([$productId]);
         return (int)$stmt->fetchColumn() > 0;
     }
     ```
  2. В `app/Controllers/ProfileController.php` в методе `deleteLot()`:
     ```php
     if ((new Order())->hasActiveOrdersForProduct((int) $id)) {
         $_SESSION['error'] = 'Невозможно удалить товар: по нему есть активный заказ в обработке или доставке.';
         $this->redirect('/profile?tab=my-listings');
         return;
     }
     if (($product['type'] ?? '') === 'auction' && ($product['status'] ?? '') === 'active') {
         if ((new Product())->countBids((int) $id) > 0) {
             $_SESSION['error'] = 'Невозможно удалить аукцион с действующими ставками.';
             $this->redirect('/profile?tab=my-listings');
             return;
         }
     }
     ```
  3. Создать SQL-миграцию `database/migrate_fix_order_cascade.sql` для изменения внешнего ключа:
     ```sql
     ALTER TABLE orders DROP FOREIGN KEY orders_ibfk_1;
     ALTER TABLE orders ADD CONSTRAINT fk_orders_product 
       FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT;
     ```
* **Запреты:** Не удалять физические файлы изображений товара, если лот архивируется, а не удаляется.
* **Критерии приёмки:** Попытка отправить `POST /profile/lots/{id}/delete` для товара с заказом в статусе `escrowed` возвращает ошибку и не удаляет строку из `orders`.

---

### ТЗ-002: Реализация удержания 10% комиссии платформы с продавца (QA-0001)

* **ID дефекта:** QA-0001
* **Приоритет:** P1 (Высокий)
* **Контекст:** В `EscrowService::settle()` продавцу переводится 100% стоимости товара (`$amountToSeller = $total - $amountToBuyer`), а платформа удерживает только арбитражный сбор покупателя (3%).
* **Затронутые файлы:**
  * `app/Services/EscrowService.php`
  * `app/Models/Wallet.php`
* **Необходимые изменения:**
  1. В `app/Services/EscrowService.php` объявить константу:
     ```php
     public const SELLER_COMMISSION_PERCENT = 10;
     ```
  2. В методе `settle()` добавить расчет комиссии с доли продавца:
     ```php
     $sellerCommission = 0;
     if ($amountToSeller > 0) {
         $sellerCommission = (int) round($amountToSeller * self::SELLER_COMMISSION_PERCENT / 100);
         $payoutToSeller = $amountToSeller - $sellerCommission;
     } else {
         $payoutToSeller = 0;
     }
     ```
  3. В блоке зачисления средств:
     ```php
     if ($payoutToSeller > 0) {
         $wallet->releaseFromEscrow($sellerId, $payoutToSeller, $orderId);
     }
     if ($sellerCommission > 0) {
         $wallet->collectPlatformCommission($sellerCommission, $orderId, 'seller_commission_10pct');
     }
     if ($arbitrationFee > 0) {
         $wallet->collectEscrowCommission($arbitrationFee, $orderId);
     }
     ```
  4. В `app/Models/Wallet.php` добавить метод `collectPlatformCommission($amount, $orderId, $reason)`.
* **Запреты:** Не взимать комиссию, если заказ полностью отменен или возвращен покупателю (`amountToSeller === 0`).
* **Критерии приёмки:** При сумме заказа 20 000 ₸ баланс продавца пополняется на 18 000 ₸, а 2 000 ₸ фиксируются как доход платформы.

---

### ТЗ-003: Корректировка окна открытия споров до 24 часов (QA-0002)

* **ID дефекта:** QA-0002
* **Приоритет:** P1 (Высокий)
* **Контекст:** Константа `INSPECT_DAYS = 3` выставляет `inspect_until` на 72 часа, что расходится с бизнес-моделью 24 часов.
* **Затронутые файлы:**
  * `app/Services/EscrowService.php`
  * `app/Controllers/OrderController.php`
* **Необходимые изменения:**
  1. В `EscrowService.php`:
     ```php
     public const INSPECT_HOURS = 24;
     ```
  2. При переводе заказа в статус `delivered` устанавливать:
     ```php
     'inspect_until' => date('Y-m-d H:i:s', time() + self::INSPECT_HOURS * 3600),
     ```
  3. В `processDeadlines()` сравнивать текущее время с `inspect_until`.
* **Критерии приёмки:** Заказ, переведенный в статус `delivered` в 12:00, автоматически завершается и выплачивается продавцу ровно через 24 часа (в 12:00 следующих суток), если спор не был открыт.

---

### ТЗ-004: Исключение маршрутов микрозадач из CSRF-фильтрации (QA-0007)

* **ID дефекта:** QA-0007
* **Приоритет:** P2 (Средний)
* **Контекст:** API-эндпоинты `/api/v1/micro-tasks/*` не добавлены в `csrfExempt()`, из-за чего внешние клиенты получают ошибку HTTP 419.
* **Затронутые файлы:**
  * `app/Core/Router.php`
* **Необходимые изменения:**
  В метод `csrfExempt(string $path): bool` добавить условие:
  ```php
  if (str_starts_with($path, '/api/v1/micro-tasks/')) {
      return true;
  }
  ```
* **Критерии приёмки:** `POST /api/v1/micro-tasks/list` и `mine` успешно принимают запросы без заголовка `_csrf` и без сессионного токена.

---

### ТЗ-005: Защита эндпоинта лайков прямых эфиров от накрутки (QA-0006)

* **ID дефекта:** QA-0006
* **Приоритет:** P2 (Средний)
* **Контекст:** Неавторизованный пользователь может в цикле отправлять тысячи запросов на `/streams/live/like` и накручивать счетчик сердец.
* **Затронутые файлы:**
  * `app/Controllers/StreamController.php`
  * `app/Models/Stream.php`
* **Необходимые изменения:**
  1. В `StreamController::like()` требовать авторизацию либо использовать сессионный rate-limiting:
     ```php
     $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
     $limitKey = 'stream_like_' . $streamId . '_' . md5($ip);
     // Ограничение: не более 20 лайков в минуту с одного IP/сессии
     ```
  2. При превышении лимита возвращать HTTP 429 Too Many Requests.
* **Критерии приёмки:** Отправка 50 параллельных запросов лайка от одного клиента приводит к блокировке после 20-го запроса с кодом 429.

