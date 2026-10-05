# CDEK Calculator — Zakopeyki.kz (Phase 6)

> Расчёт стоимости доставки. **Оплата и создание CDEK-заказа — следующий этап.**

Companion: `docs/cdek-data-mapping.md`, `docs/cdek-api-client.md`.

---

## 1. Endpoint

| Выбор | Endpoint | Причина |
|---|---|---|
| **Primary** | `POST /v2/calculator/tarifflist` | Покупатель выбирает среди тарифов (door/PVZ); нужен список + `delivery_sum` + периоды. Подтверждено audit + OpenAPI `CalculatorTariffListRequestDto`. |
| Revalidation (позже) | `POST /v2/calculator/tariff` | Перед оплатой / create — сверка выбранного `tariff_code`. **Не этот этап.** |
| Не используем live | `tariffAndService`, `alltariffs` | Services pricing / каталог — не основной checkout calc. |

Реализация: `CdekCalculatorService` → `CdekApi::calculateTariffList()` → единый `Client`.

---

## 2. Request (только backend)

Frontend **не** передаёт `CalculatorTariffListRequestDto` и **не** передаёт сумму.

Backend сам берёт из delivery order:

| Источник | CDEK field |
|---|---|
| Point A `shipment_point` XOR `cdek_city_code` | `shipment_point` XOR `from_location.code` |
| Point B `delivery_point`/`pvz_code` XOR door address + city code | `delivery_point` XOR `to_location.*` |
| Shipment weight kg → grams | `packages[].weight` (R) |
| Shipment L/W/H cm | `packages[].length/width/height` (O) |
| config `order_type`, `currency`, `lang` | optional top-level |

Сборщик: `CdekCalculatorRequestBuilder` (whitelist OpenAPI полей).

---

## 3. Pre-checks (без вызова CDEK)

`CdekCalculatorService::validateReady()`:

1. delivery order / product link exists  
2. Point A present  
3. Point B present  
4. Shipment present  
5. weight > 0 (или packaging+dimensions_unknown)  
6. delivery_mode ∈ {courier, pvz}  
7. PVZ code required for pvz mode  
8. city / CDEK identifiers resolvable  

Ownership: `DeliveryService::calculateQuotesForBuyer` — только `buyer_user_id`.

---

## 4. Response → Delivery Quote

`CdekCalculatorResponseMapper` → rows in `delivery_quotes`:

| Field | Meaning |
|---|---|
| `cdek_delivery_sum` / `base_amount` | Чистая сумма CDEK (`delivery_sum` → INT KZT) |
| `packaging_amount` / `handling_amount` / `extra_services_amount` | Платформенные надбавки (не скрываются) |
| `total_amount` = **`delivery_amount_to_pay`** | 100% к оплате покупателем (платёж — Phase 7) |
| `currency` | `KZT` |
| `tariff_code`, `service_name`, ETA | из TariffCodeDto |
| `valid_until` | **внутренний TTL 7200s** (CDEK OpenAPI **не** задаёт срок действия quote — GAP) |
| `request_payload_hash` / `route_hash` / `package_hash` | idempotency / invalidation |
| `snapshot_json` | Point A/B/shipment + CDEK tariff audit |
| `quote_status` | `active` → при новом calc старые `superseded`/`invalidated` |

Деньги: INT тенге через `MoneyAmount` (без float-цепочек).

---

## 5. Tariff selection rule

CDEK может вернуть несколько тарифов.

**Правило проекта:** фильтр по `delivery_mode` (PVZ vs courier); оставшиеся сортируются по цене; покупатель **явно выбирает** тариф (`POST /delivery/{id}/quote`).  
Молчаливый auto-select для оплаты **не** делается на этом этапе.

---

## 6. Invalidation

Quote устаревает, если изменились:

- Point A / Point B / PVZ / адрес  
- вес / габариты / shipment  
- тип доставки / listing shipping version  

Механика: `invalidateQuotes(reason)` + reset status → `DATA_COMPLETE`; нужен новый `POST .../quotes/calculate`.  
Reuse: тот же `request_hash` + `shipping_version` → local reuse без повторного HTTP (`CdekQuoteReuseService`).

---

## 7. API

| Method | Path | Role |
|---|---|---|
| POST | `/delivery/{id}/quotes/calculate` | Рассчитать (buyer) |
| POST | `/delivery/{id}/quote` | Выбрать тариф (buyer) |
| GET | `/delivery/{id}` | UI со стоимостью |

JSON (`Accept: application/json`): `{ ok, quotes[{id, delivery_amount_to_pay, currency, tariff_code, eta_*, valid_until, quote_status}], error_code }`.

---

## 8. Errors (user-facing, без tech dump)

| Case | internal / HTTP | UI key |
|---|---|---|
| Missing data | local codes | `delivery.missing_*` |
| Bad route / no tariff | `TARIFF_UNAVAILABLE` | `quote_route_unavailable` |
| 400 validation | `CDEK_VALIDATION_ERROR` | `quote_validation_error` |
| 429 | `CDEK_RATE_LIMIT` | `quote_rate_limited` |
| 5xx | `CDEK_SERVER_ERROR` | `quote_server_error` |
| timeout | `CDEK_TIMEOUT` | `quote_timeout` |
| network | `CDEK_NETWORK_ERROR` | `quote_network_error` |

При ошибке **Delivery Quote не создаётся**.

---

## 9. GAP

1. Официальный TTL quote от CDEK — **не документирован**; используем 7200s.  
2. Обязательность габаритов для tarifflist — OpenAPI optional; вес required.  
3. Revalidation через `/calculator/tariff` — следующий платежный этап.  
4. Preview-расчёт на product checkout до создания delivery_order — не реализован (расчёт на `/delivery/{id}` после escrow + Point B).  
5. Полная матрица platform surcharges vs «чистый CDEK» в UI — базово разделены в quote fields.

---

## 10. Tests

`php tools/run_cdek_calculator_phase6_tests.php`
