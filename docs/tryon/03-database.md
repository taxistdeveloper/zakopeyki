# 03 — База данных (проект)

Проект DDL; миграция будет создана в PHASE 3 как `database/migrate_tryon.sql` + `ensureTable()` в моделях (оба механизма уже используются в проекте). Production-миграции вручную не запускаются.

Типы ключей совпадают с существующими: `users.id`, `products.id` — `INT UNSIGNED`.

## 1. `products.tryon_type` (новая колонка)

```sql
ALTER TABLE products
  ADD COLUMN tryon_type VARCHAR(16) DEFAULT NULL AFTER category;
-- NULL = примерка не поддерживается
-- upper | lower | dress | shoes | glasses | hat | bag | jewelry | watch
```

Заполняет продавец в форме объявления (D3), только для категорий из раздела «Одежда, обувь и аксессуары» и «Детская одежда и обувь». Список допустимых значений — `TryOnTypes`; включённые в данный момент — настройка `tryon_types_enabled`. Индекс не нужен (фильтрации каталога по полю нет).

## 2. `tryon_jobs` — задания (одновременно очередь)

```sql
CREATE TABLE IF NOT EXISTS tryon_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,               -- NULL, если объявление удалено
  tryon_type VARCHAR(16) NOT NULL,
  garment_image_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'queued', -- queued|processing|succeeded|failed|cancelled|expired
  provider VARCHAR(32) DEFAULT NULL,          -- genlook | fashn | fake
  provider_model VARCHAR(64) DEFAULT NULL,
  external_id VARCHAR(128) DEFAULT NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_poll_at DATETIME DEFAULT NULL,
  locked_until DATETIME DEFAULT NULL,
  locked_by VARCHAR(64) DEFAULT NULL,
  cost_estimate_micros INT UNSIGNED NOT NULL DEFAULT 0,  -- USD * 1e6, резерв бюджета
  cost_actual_micros INT UNSIGNED DEFAULT NULL,          -- NULL = неизвестно; 0 = не списано
  error_code VARCHAR(64) DEFAULT NULL,
  consent_version VARCHAR(32) NOT NULL,
  consent_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uk_tryon_public (public_id),
  UNIQUE KEY uk_tryon_idem (user_id, idempotency_key),
  INDEX idx_tryon_worker (status, next_poll_at),
  INDEX idx_tryon_user (user_id, created_at),
  INDEX idx_tryon_expires (expires_at),
  CONSTRAINT fk_tryon_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tryon_jobs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Примечания:
- `ON DELETE CASCADE` по пользователю удаляет строки, но **не файлы** — удаление аккаунта должно сначала вызвать `TryOnService::purgeUser()` (файлы + `DELETE /customers/{externalUserId}` у Genlook). Точка вызова — существующий код удаления аккаунта [найти в PHASE 3].
- Почему без отдельной `TryOnUsage`: лимиты и расходы — агрегаты по `tryon_jobs` (`COUNT`/`SUM` по `user_id, created_at` / `provider, created_at`); объём — тысячи строк в месяц. Если понадобится — суточный агрегат добавляется позже без изменения контрактов.
- Строки хранятся 12 месяцев для учёта расходов; персональных данных в них нет (только ID пользователя и метаданные).

## 3. `tryon_assets` — файлы

```sql
CREATE TABLE IF NOT EXISTS tryon_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,                  -- person | result
  storage_path VARCHAR(255) DEFAULT NULL,     -- относительно storage/tryon; NULL после удаления
  mime VARCHAR(32) NOT NULL DEFAULT 'image/jpeg',
  bytes INT UNSIGNED NOT NULL DEFAULT 0,
  width SMALLINT UNSIGNED DEFAULT NULL,
  height SMALLINT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,               -- для дедупликации повторной оплаты
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  deleted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uk_tryon_asset (job_id, kind),
  INDEX idx_tryon_assets_expires (deleted_at, expires_at),
  CONSTRAINT fk_tryon_assets_job FOREIGN KEY (job_id) REFERENCES tryon_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Фото товара не копируется — берётся из `public/uploads/products` в момент отправки.

## 4. Настройки — существующая таблица `settings`

| Ключ | По умолчанию | Назначение |
|---|---|---|
| `tryon_enabled` | `0` | главный выключатель |
| `tryon_beta_users` | `""` | ID через запятую: доступ при выключенном модуле (бета) |
| `tryon_types_enabled` | `upper,lower,dress` | включённые типы |
| `tryon_providers_order` | `genlook,fashn` | порядок провайдеров |
| `tryon_provider_genlook_enabled` / `_fashn_enabled` | `1` / `0` | вкл/выкл провайдера |
| `tryon_user_daily_limit` / `tryon_user_monthly_limit` | `3` / `20` | бесплатные лимиты (D2) |
| `tryon_user_max_active` | `1` | одновременных заданий на пользователя |
| `tryon_max_concurrent` | `5` | одновременных заданий в системе |
| `tryon_monthly_budget_usd` | `50` | месячный потолок расходов (D1) |
| `tryon_retention_hours` | `24` | срок хранения фото/результатов (D5) |
| `tryon_consent_version` | `2026-11-v1` | версия текста согласия |

Ключи API в БД **не хранятся**: только `getenv('TRYON_GENLOOK_API_KEY')`, `getenv('TRYON_FASHN_API_KEY')`, `getenv('TRYON_USER_HASH_SECRET')` через `config/tryon.php`.

## 5. Повторная оплата

1. Тот же `Idempotency-Key` → тот же job.
2. Тот же пользователь + товар + индекс фото товара + `sha256` фото за последние `retention_hours`, статус `succeeded` → ответ ссылкой на существующий job (`idempotent: true`, `reused: true`), без генерации и списания.
3. `failed` у провайдера → `cost_actual_micros = 0` (Genlook и FASHN не списывают неудачные генерации — подтвердить по счёту в PHASE 2); лимит пользователя за `failed` не списывается.
