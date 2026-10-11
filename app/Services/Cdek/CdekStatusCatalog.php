<?php

namespace App\Services\Cdek;

use App\Models\DeliveryOrder;

/**
 * Единый каталог кодов, которые уже маппились в CdekLogisticsProvider и CdekReconciliationService.
 *
 * OpenAPI OrderStatusDto.code — свободная строка, enum в спецификации нет.
 * Неизвестный код не угадывается: группа null, текст «Статус уточняется», GAP.
 */
final class CdekStatusCatalog
{
    public const GROUP_REGISTERED = 'REGISTERED';
    public const GROUP_ACCEPTED = 'ACCEPTED';
    public const GROUP_IN_TRANSIT = 'IN_TRANSIT';
    public const GROUP_READY_FOR_PICKUP = 'READY_FOR_PICKUP';
    public const GROUP_DELIVERED = 'DELIVERED';
    public const GROUP_EXCEPTION = 'EXCEPTION';
    public const GROUP_UNKNOWN = 'UNKNOWN';

    /**
     * @var array<string, array{
     *   group: string,
     *   buyer_key: string,
     *   seller_key: string,
     *   buyer: string,
     *   seller: string,
     *   meaning: string,
     *   needs_get: bool,
     *   notice: string|null
     * }>
     */
    private const CODES = [
        'CREATED' => [
            'group' => self::GROUP_REGISTERED,
            'buyer_key' => 'delivery.track_registered_buyer',
            'seller_key' => 'delivery.track_registered_seller',
            'buyer' => 'Заказ зарегистрирован в CDEK. Передача посылки ещё не подтверждена.',
            'seller' => 'Заказ зарегистрирован в CDEK. Передача посылки ещё не подтверждена.',
            'meaning' => 'В OpenAPI enum нет. В прежнем маппере код шёл в одну группу с ACCEPTED; для пользователя это только регистрация заказа, не сдача перевозчику.',
            'needs_get' => true,
            'notice' => 'registered',
        ],
        'ACCEPTED' => [
            'group' => self::GROUP_ACCEPTED,
            'buyer_key' => 'delivery.track_accepted_buyer',
            'seller_key' => 'delivery.track_accepted_seller',
            'buyer' => 'CDEK подтвердил приём отправления.',
            'seller' => 'CDEK подтвердил приём вашего отправления.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: приём к перевозке (не завершение доставки).',
            'needs_get' => false,
            'notice' => 'accepted',
        ],
        'RECEIVED_AT_SHIPMENT_WAREHOUSE' => [
            'group' => self::GROUP_ACCEPTED,
            'buyer_key' => 'delivery.track_accepted_buyer',
            'seller_key' => 'delivery.track_accepted_seller',
            'buyer' => 'CDEK подтвердил приём отправления.',
            'seller' => 'CDEK подтвердил приём вашего отправления.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: складская приёмка, группа ACCEPTED.',
            'needs_get' => false,
            'notice' => 'accepted',
        ],
        'READY_FOR_SHIPMENT_IN_SENDER_CITY' => [
            'group' => self::GROUP_ACCEPTED,
            'buyer_key' => 'delivery.track_accepted_buyer',
            'seller_key' => 'delivery.track_accepted_seller',
            'buyer' => 'CDEK подтвердил приём отправления.',
            'seller' => 'CDEK подтвердил приём вашего отправления.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: готово к отправке в городе отправителя, группа ACCEPTED.',
            'needs_get' => false,
            'notice' => 'accepted',
        ],
        'READY_FOR_SHIPMENT_IN_TRANSIT_CITY' => [
            'group' => self::GROUP_ACCEPTED,
            'buyer_key' => 'delivery.track_accepted_buyer',
            'seller_key' => 'delivery.track_accepted_seller',
            'buyer' => 'CDEK подтвердил приём отправления.',
            'seller' => 'CDEK подтвердил приём вашего отправления.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: готово к отправке в транзитном городе, группа ACCEPTED.',
            'needs_get' => false,
            'notice' => 'accepted',
        ],
        'PASSED_TO_CARRIER_AT_SENDER_CITY' => [
            'group' => self::GROUP_ACCEPTED,
            'buyer_key' => 'delivery.track_accepted_buyer',
            'seller_key' => 'delivery.track_accepted_seller',
            'buyer' => 'CDEK подтвердил приём отправления.',
            'seller' => 'CDEK подтвердил приём вашего отправления.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: передано перевозчику в городе отправителя, группа ACCEPTED.',
            'needs_get' => false,
            'notice' => 'accepted',
        ],
        'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY' => [
            'group' => self::GROUP_IN_TRANSIT,
            'buyer_key' => 'delivery.track_transit_buyer',
            'seller_key' => 'delivery.track_transit_seller',
            'buyer' => 'Отправление в пути.',
            'seller' => 'Отправление в пути.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: промежуточное движение, группа IN_TRANSIT.',
            'needs_get' => false,
            'notice' => null,
        ],
        'SENT_TO_RECIPIENT_CITY' => [
            'group' => self::GROUP_IN_TRANSIT,
            'buyer_key' => 'delivery.track_transit_buyer',
            'seller_key' => 'delivery.track_transit_seller',
            'buyer' => 'Отправление в пути.',
            'seller' => 'Отправление в пути.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: отправлено в город получателя, группа IN_TRANSIT.',
            'needs_get' => false,
            'notice' => null,
        ],
        'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE' => [
            'group' => self::GROUP_IN_TRANSIT,
            'buyer_key' => 'delivery.track_transit_buyer',
            'seller_key' => 'delivery.track_transit_seller',
            'buyer' => 'Отправление в пути.',
            'seller' => 'Отправление в пути.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: склад города получателя, группа IN_TRANSIT.',
            'needs_get' => false,
            'notice' => null,
        ],
        'ACCEPTED_AT_TRANSIT_WAREHOUSE' => [
            'group' => self::GROUP_IN_TRANSIT,
            'buyer_key' => 'delivery.track_transit_buyer',
            'seller_key' => 'delivery.track_transit_seller',
            'buyer' => 'Отправление в пути.',
            'seller' => 'Отправление в пути.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: транзитный склад, группа IN_TRANSIT.',
            'needs_get' => false,
            'notice' => null,
        ],
        'TAKEN_BY_COURIER' => [
            'group' => self::GROUP_IN_TRANSIT,
            'buyer_key' => 'delivery.track_transit_buyer',
            'seller_key' => 'delivery.track_transit_seller',
            'buyer' => 'Отправление в пути.',
            'seller' => 'Отправление в пути.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: у курьера, группа IN_TRANSIT. Это не DELIVERED.',
            'needs_get' => false,
            'notice' => null,
        ],
        'RECEIVED_AT_SENDER_WAREHOUSE' => [
            'group' => self::GROUP_IN_TRANSIT,
            'buyer_key' => 'delivery.track_transit_buyer',
            'seller_key' => 'delivery.track_transit_seller',
            'buyer' => 'Отправление в пути.',
            'seller' => 'Отправление в пути.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: склад отправителя, группа IN_TRANSIT.',
            'needs_get' => false,
            'notice' => null,
        ],
        'RETURNED_TO_SENDER_CITY' => [
            'group' => self::GROUP_IN_TRANSIT,
            'buyer_key' => 'delivery.track_transit_buyer',
            'seller_key' => 'delivery.track_transit_seller',
            'buyer' => 'Отправление в пути.',
            'seller' => 'Отправление в пути.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта относит код к IN_TRANSIT. Отдельный сценарий возврата на этом этапе не реализуется (GAP).',
            'needs_get' => true,
            'notice' => null,
        ],
        'READY_FOR_PICKUP' => [
            'group' => self::GROUP_READY_FOR_PICKUP,
            'buyer_key' => 'delivery.track_pickup_buyer',
            'seller_key' => 'delivery.track_pickup_seller',
            'buyer' => 'Отправление в пункте выдачи.',
            'seller' => 'Отправление в пункте выдачи у получателя.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: пункт выдачи. В FSM хранится IN_TRANSIT, отдельного статуса нет.',
            'needs_get' => false,
            'notice' => 'pickup',
        ],
        'ACCEPTED_AT_PICK_UP_POINT' => [
            'group' => self::GROUP_READY_FOR_PICKUP,
            'buyer_key' => 'delivery.track_pickup_buyer',
            'seller_key' => 'delivery.track_pickup_seller',
            'buyer' => 'Отправление в пункте выдачи.',
            'seller' => 'Отправление в пункте выдачи у получателя.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: принято в пункте выдачи. FSM: IN_TRANSIT.',
            'needs_get' => false,
            'notice' => 'pickup',
        ],
        'POSTOMAT_POSTED' => [
            'group' => self::GROUP_READY_FOR_PICKUP,
            'buyer_key' => 'delivery.track_pickup_buyer',
            'seller_key' => 'delivery.track_pickup_seller',
            'buyer' => 'Отправление в пункте выдачи.',
            'seller' => 'Отправление в пункте выдачи у получателя.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: заложено в постамат. FSM: IN_TRANSIT. Это не получение.',
            'needs_get' => false,
            'notice' => 'pickup',
        ],
        'POSTOMAT_SEIZED' => [
            'group' => self::GROUP_READY_FOR_PICKUP,
            'buyer_key' => 'delivery.track_postomat_seized_buyer',
            'seller_key' => 'delivery.track_postomat_seized_seller',
            'buyer' => 'Статус в пункте выдачи изменился. Уточняем детали.',
            'seller' => 'Статус в пункте выдачи изменился. Уточняем детали.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта держит код в группе пункта выдачи. Текст не утверждает ни выдачу, ни проблему: семантика SEIZED в спецификации не описана (GAP).',
            'needs_get' => true,
            'notice' => null,
        ],
        'DELIVERED' => [
            'group' => self::GROUP_DELIVERED,
            'buyer_key' => 'delivery.track_delivered_buyer',
            'seller_key' => 'delivery.track_delivered_seller',
            'buyer' => 'CDEK подтвердил завершение доставки.',
            'seller' => 'CDEK подтвердил завершение доставки.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: финальная доставка. FSM: DELIVERED, дальше откат запрещён.',
            'needs_get' => false,
            'notice' => 'delivered',
        ],
        'POSTOMAT_RECEIVED' => [
            'group' => self::GROUP_DELIVERED,
            'buyer_key' => 'delivery.track_delivered_buyer',
            'seller_key' => 'delivery.track_delivered_seller',
            'buyer' => 'CDEK подтвердил завершение доставки.',
            'seller' => 'CDEK подтвердил завершение доставки.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта приравнивает код к DELIVERED.',
            'needs_get' => false,
            'notice' => 'delivered',
        ],
        'NOT_DELIVERED' => [
            'group' => self::GROUP_EXCEPTION,
            'buyer_key' => 'delivery.track_problem_buyer',
            'seller_key' => 'delivery.track_problem_seller',
            'buyer' => 'CDEK сообщил о проблеме с доставкой.',
            'seller' => 'CDEK сообщил о проблеме с доставкой.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: EXCEPTION. Не трактуется как успешная доставка и не запускает возврат денег.',
            'needs_get' => true,
            'notice' => 'problem',
        ],
        'INVALID' => [
            'group' => self::GROUP_EXCEPTION,
            'buyer_key' => 'delivery.track_problem_buyer',
            'seller_key' => 'delivery.track_problem_seller',
            'buyer' => 'CDEK сообщил о проблеме с доставкой.',
            'seller' => 'CDEK сообщил о проблеме с доставкой.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: EXCEPTION. Тот же код может прийти как state запроса на создание; tracking не создаёт новый заказ.',
            'needs_get' => true,
            'notice' => 'problem',
        ],
        'REMOVED_FROM_PICKUP_POINT' => [
            'group' => self::GROUP_EXCEPTION,
            'buyer_key' => 'delivery.track_problem_buyer',
            'seller_key' => 'delivery.track_problem_seller',
            'buyer' => 'CDEK сообщил о проблеме с доставкой.',
            'seller' => 'CDEK сообщил о проблеме с доставкой.',
            'meaning' => 'В OpenAPI enum нет. Маппер проекта: EXCEPTION. Сценарий возврата на этом этапе не реализуется (GAP).',
            'needs_get' => true,
            'notice' => 'problem',
        ],
    ];

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function entry(string $code): ?array
    {
        $code = self::normalize($code);
        return self::CODES[$code] ?? null;
    }

    public static function isKnown(string $code): bool
    {
        return self::entry($code) !== null;
    }

    public static function group(string $code): ?string
    {
        $entry = self::entry($code);
        return $entry !== null ? (string) $entry['group'] : null;
    }

    public static function noticeKind(string $code): ?string
    {
        $entry = self::entry($code);
        if ($entry === null) {
            return null;
        }
        $kind = $entry['notice'];
        return is_string($kind) && $kind !== '' ? $kind : null;
    }

    public static function needsGet(string $code): bool
    {
        if (!self::isKnown($code)) {
            return true;
        }
        return !empty(self::entry($code)['needs_get']);
    }

    public static function text(string $code, string $audience = 'buyer'): string
    {
        $entry = self::entry($code);
        if ($entry === null) {
            $key = 'delivery.track_unknown';
            $label = function_exists('t') ? t($key) : $key;
            return $label !== $key ? $label : 'Статус уточняется';
        }
        $seller = $audience === 'seller';
        $key = $seller ? (string) $entry['seller_key'] : (string) $entry['buyer_key'];
        $fallback = $seller ? (string) $entry['seller'] : (string) $entry['buyer'];
        if (!function_exists('t')) {
            return $fallback;
        }
        $label = t($key);
        return $label !== $key ? $label : $fallback;
    }

    public static function awaitingCarrierText(string $audience = 'buyer'): string
    {
        $key = $audience === 'seller'
            ? 'delivery.track_awaiting_carrier_seller'
            : 'delivery.track_awaiting_carrier_buyer';
        $fallback = 'Передача посылки перевозчику ещё не подтверждена.';
        if (!function_exists('t')) {
            return $fallback;
        }
        $label = t($key);
        return $label !== $key ? $label : $fallback;
    }

    /**
     * Цель FSM для группы webhook. REGISTERED и UNKNOWN статус доставки не двигают.
     */
    public static function fsmForGroup(string $group): ?string
    {
        return match ($group) {
            self::GROUP_ACCEPTED => DeliveryOrder::STATUS_ACCEPTED,
            'SHIPMENT_RECEIVED' => DeliveryOrder::STATUS_SHIPMENT_RECEIVED,
            self::GROUP_IN_TRANSIT, self::GROUP_READY_FOR_PICKUP => DeliveryOrder::STATUS_IN_TRANSIT,
            self::GROUP_DELIVERED => DeliveryOrder::STATUS_DELIVERED,
            self::GROUP_EXCEPTION => DeliveryOrder::STATUS_EXCEPTION,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $out = [];
        foreach (self::CODES as $code => $entry) {
            $out[] = [
                'code' => $code,
                'meaning' => $entry['meaning'],
                'group' => $entry['group'],
                'fsm' => self::fsmForGroup((string) $entry['group']),
                'buyer' => $entry['buyer'],
                'seller' => $entry['seller'],
                'needs_get' => (bool) $entry['needs_get'],
                'notice' => $entry['notice'],
                'transitions' => self::transitionNote((string) $entry['group']),
            ];
        }
        return $out;
    }

    public static function transitionNote(string $group): string
    {
        return match ($group) {
            self::GROUP_REGISTERED => 'FSM не меняется. Регистрация не доказывает сдачу и не ставит DELIVERED.',
            self::GROUP_ACCEPTED => 'Разрешён переход в DELIVERY_ACCEPTED, если rank не ниже текущего. Откат с DELIVERED запрещён.',
            self::GROUP_IN_TRANSIT => 'Разрешён переход в IN_TRANSIT вперёд по rank. Откат с DELIVERED запрещён.',
            self::GROUP_READY_FOR_PICKUP => 'FSM остаётся IN_TRANSIT (отдельного статуса пункта выдачи нет). Откат с DELIVERED запрещён.',
            self::GROUP_DELIVERED => 'DELIVERED терминальный. Более поздний код с меньшим rank не откатывает его.',
            self::GROUP_EXCEPTION => 'EXCEPTION допустим, пока статус не DELIVERED. После DELIVERED не применяется.',
            default => 'Неизвестный код статус доставки не меняет.',
        };
    }
}
