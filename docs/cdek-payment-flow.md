# CDEK Delivery Payment Flow — Zakopeyki.kz (Phase 7)

> Оплата 100% стоимости доставки. **Создание CDEK `/v2/orders` — следующий этап.**

Companion: `docs/cdek-state-machine.md`, `docs/cdek-calculator.md`.

---

## 1. Источник суммы

| Правило | Деталь |
|---|---|
| Source of truth | `delivery_quotes.total_amount` (= `delivery_amount_to_pay`) выбранного active quote |
| Frontend | **не** передаёт amount/currency (игнорируются) |
| Currency | INT KZT в quote/payment |

---

## 2. Quote validation before payment

`DeliveryPaymentService::assertQuotePayable` + `ShippingQuoteGuard::revalidateForPayment`:

- quote exists, selected, `active`
- not expired (`valid_until`)
- Point A / Point B / Shipment present
- `shipping_version` match
- live CDEK tarifflist recheck: same `service_code` & same INT amount

If stale → invalidate quotes → require new calculate. **No payment on old quote.**

---

## 3. Payment creation

`POST /delivery/{id}/pay` → `DeliveryPaymentService::createPaymentIntent`

Snapshot in `delivery_payments.meta` + `quote_id`:

- order/delivery ids, quote id, amount, currency
- fingerprints Point A/B/shipment
- shipping_version, tariff, created_at

Gateway via `PaymentGatewayInterface` (`FreedomPayGateway` adapter).  
CDEK delivery code **не** знает имя эквайера.

Idempotency key: `del-pay-{deliveryId}-{quoteId}-{amount}-{currency}` (UNIQUE).

---

## 4. Webhook verification

`POST /payments/freedompay/result` (существующий):

1. `verifySig` — иначе reject  
2. branch `zk-del-*` → `DeliveryPayment`  
3. `completeFromGateway(amount, currency, webhook_hash)`  
4. `paidAmount >= expected` (INT)  
5. currency match  
6. `FOR UPDATE` + if already `paid` → idempotent OK  

Redirect success/failure **не** выставляет PAID.

---

## 5. Payment confirmation

Only after validated callback:

`delivery_payments.status = paid`  
→ `delivery_orders.status = DELIVERY_PAID`  
→ quote `paid_snapshot`  

**Does not** call CDEK create. Sets `create_idempotency_key` for Phase 8.

---

## 6. States

| delivery_payments | delivery_orders |
|---|---|
| pending | DELIVERY_PAYMENT_PENDING |
| paid | DELIVERY_PAID |
| failed | back to READY_FOR_PAYMENT |
| refunded | cannot proceed to CDEK without new paid payment |
| cancelled | — |

---

## 7. Idempotency & races

- UNIQUE `pg_order_id`, `idempotency_key`
- `SELECT … FOR UPDATE` on complete
- Duplicate webhook → no second paid / no second CDEK
- Pending payment blocks second intent for same order

---

## 8. `canCreateCdekOrder()`

True only if:

- delivery exists, CDEK provider
- Point A/B/Shipment valid
- quote active/paid_snapshot, not stale (or paid snapshot)
- payment exists & **paid** (not refunded)
- paid amount ≥ deliveryAmountToPay
- currency match
- no cdek_uuid yet
- cdek_api_status ≠ created; UUID+accepted already registered (poll path)
- status ∈ {DELIVERY_PAID, CDEK_ORDER_PENDING, CDEK_ORDER_FAILED}

Used by `CdekOrderRegistrationService` / `DeliveryService::createLogisticsOrder` — frontend cannot bypass.

API: `GET /delivery/{id}/cdek-ready` · `POST /delivery/{id}/cdek/register`

---

## 9. Failed / refund

| Event | Behavior |
|---|---|
| failed | payment failed; READY_FOR_PAYMENT; **no CDEK** |
| refunded before CDEK | payment_status refunded; need new paid payment |
| refunded after CDEK create | **GAP** — lifecycle/cancellation stage |

---

## 10. API

| Method | Path |
|---|---|
| POST | `/delivery/{id}/pay` — create intent |
| GET | `/delivery/{id}/payment` — status |
| GET | `/delivery/{id}/cdek-ready` — gate |
| POST | `/delivery/{id}/cdek/register` — Phase 8 CDEK create (PAID only) |
| POST | `/payments/freedompay/result` — webhook |

---

## 11. GAP

1. Refund after CDEK order created — separate lifecycle.  
2. Additional acquirers beyond FreedomPay — factory ready, adapters TBD.  
3. Inbound CDEK webhooks — next stage (poll fallback exists: `bin/cdek_order_poll.php`).
