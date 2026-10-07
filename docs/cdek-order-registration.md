# CDEK Order Registration — Phase 8

> Companion: `docs/cdek-data-mapping.md`, `docs/cdek-state-machine.md`, `docs/cdek-payment-flow.md`, `docs/cdek-api-client.md`.  
> **Webhooks inbound processing is NOT this stage** (next phase).

---

## 1. Preconditions

Server must re-check before `POST /v2/orders` (`DeliveryPaymentService::canCreateCdekOrder` + `assertPaidBarrier`):

| Check | Source |
|---|---|
| Purchase / delivery exists | `delivery_orders` |
| Seller / buyer correct | `seller_user_id` / `buyer_user_id` + ownership on trigger |
| CDEK selected | `logistics_providers.code = cdek` |
| Point A | `delivery_senders` |
| Point B | `delivery_recipients` |
| Shipment | `delivery_shipments` |
| Active / paid_snapshot quote | `delivery_quotes` |
| Quote not stale | `valid_until` or `paid_snapshot` |
| Quote amount fixed | `total_amount` / `delivery_amount_to_pay` |
| Payment = PAID | `delivery_payments.status = paid` |
| Paid covers 100% | `payment.amount >= quote.total_amount` |
| Currency match | quote ↔ payment |
| CDEK order not already created | empty `cdek_uuid` / `logistics_order_id` |
| No conflicting CREATED status | `cdek_api_status != created` |

Feature gate: `CDEK_ORDER_CREATE_ENABLED=1` (`DeliveryModelService::isOrderCreateEnabled`).

Payment confirm (`onPaymentConfirmed`) **does not** call create — buyer/system triggers `POST /delivery/{id}/cdek/register`.

---

## 2. OrderCreate mapping

Built by `CdekOrderPayloadBuilder::buildFromAvr` from **server** AVR context (never from frontend body).

| Domain | CDEK field | Source |
|---|---|---|
| IM number | `number` | `delivery_orders.order_number` (stable) |
| Tariff | `tariff_code` | selected quote `cdek_N` / `tariff_code` |
| Type | `type` | `config/cdek.order_type` (default 1) |
| Developer key | `developer_key` | `zk-del-{deliveryOrderId}` |
| Sender | `sender.*` | seller / Point A contact |
| Recipient | `recipient.*` | buyer / Point B contact |
| Origin | `shipment_point` XOR `from_location` | Point A |
| Destination | `delivery_point` XOR `to_location` | Point B |
| Package | `packages[0]` | shipment weight/dims |
| Item | `packages[0].items[0]` | purchase title/weight; `payment.value=0` |

Zakopeyki is **not** sender and **not** recipient.

---

## 3. Internal number (IM)

- Generated once at delivery bootstrap: `DO-YYYY-hex` (`UNIQUE order_number`).
- Stored on `delivery_orders` **before** first CDEK call.
- Retries reuse the same number — never mint a new IM for the same delivery.
- On CDEK “number already used” / similar-order: resolve via `GET /v2/orders?im_number=…` — **do not** auto-create a new number without verifying the existing CDEK order.

---

## 4. Idempotency

Layers:

1. **DB UNIQUE** `cdek_uuid`, `order_number`, `create_idempotency_key`.
2. **`SELECT … FOR UPDATE`** on `delivery_orders` before mark-pending + HTTP.
3. **MicroTaskLock** inside `CdekOrderService::create` (`cdek-order-create-{id}`).
4. If `cdek_uuid` already stored → **no second POST**; run `pollOne` instead.
5. Pre-POST `GET ?im_number=` recovery after timeout / duplicate response.

---

## 5. HTTP 202 / ACCEPTED handling

| Signal | Local effect |
|---|---|
| HTTP 202 or `requests[].state = ACCEPTED` | `CDEK_ORDER_PENDING` + `cdek_api_status=accepted` |
| UUID in response | store `cdek_uuid` / `logistics_order_id` |
| `cdek_number` absent | stay async (not `DELIVERY_ORDER_CREATED` yet) |
| `requests[].state = SUCCESSFUL` or `cdek_number` | `DELIVERY_ORDER_CREATED` → `DELIVERY_ACCEPTED` |
| `requests[].state = INVALID` | `CDEK_ORDER_FAILED` + structured `last_error_*` |

**Do not** treat API ACCEPTED as final SUCCESSFUL create.

---

## 6. Polling / background job

- **No aggressive inline poll** after POST.
- Job: `bin/cdek_order_poll.php` → `CdekOrderRegistrationService::pollPending`.
- Selection: `findCdekPendingPoll` — status `CDEK_ORDER_PENDING`, api `pending|accepted`, skip rows synced &lt; 3s ago (CDEK first-GET recommendation).
- Preferred GET: `GET /v2/orders/{uuid}`; fallback `GET /v2/orders?im_number=`.
- Cap: limit 1–100 per run; safe for cron every 1–2 minutes.
- Full webhook pipeline = next stage.

---

## 7. Retry after INVALID

- Persist status + error code/message + timestamp.
- Do **not** auto-create a second CDEK order.
- If data is fixable: status `CDEK_ORDER_FAILED` allows buyer retry via same endpoint (same IM).
- If not safely retryable → ops / GAP / refund path (existing `REFUND_REQUIRED` when applicable).

---

## 8. Roles

| Actor | Role |
|---|---|
| Seller | CDEK `sender`, Point A |
| Buyer | delivery customer / payer / `recipient`, Point B |
| Zakopeyki | platform operator + API credentials holder; **not** sender/recipient |

---

## 9. Security

- Register endpoint strips client-supplied uuid / im_number / amounts / points / quote / payment ids.
- Ownership: only buyer may trigger register.
- PII redacted in `delivery_events` via `DeliveryPii`.
- Secrets never logged by CDEK client logger.

---

## 10. GAP (unchanged legal)

Legal АВР / marketplace billing party when API account is the platform’s but delivery customer is the buyer — **must be confirmed with CDEK**. Local `delivery_documents.avr_data` is platform-side only; OpenAPI `seller` (SellerDto) is optional and not required for create today.

---

## 11. Entry points

| Entry | Behavior |
|---|---|
| `POST /delivery/{id}/cdek/register` | Buyer UI / explicit trigger |
| `DeliveryService::createLogisticsOrder` | Delegates to `CdekOrderRegistrationService` when create enabled |
| `bin/cdek_order_poll.php` | Deferred SUCCESSFUL/INVALID resolution |

Env: `CDEK_ORDER_CREATE_ENABLED=1` required for real POST.
