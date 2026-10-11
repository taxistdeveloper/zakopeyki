# CDEK locations and delivery points — Phase 11

Point B покупателя. Point A продавца не переписывается. Заказ CDEK и оплата не создаются при выборе пункта.

## Endpoints CDEK

| Endpoint | Использование |
|---|---|
| `GET /v2/location/suggest/cities` | Поиск города по тексту (`name`, `country_code`) |
| `GET /v2/location/cities` | Подтверждение `code` |
| `GET /v2/location/regions` | Список регионов страны |
| `GET /v2/location/postalcodes` | Индексы города, если индекс указан |
| `GET /v2/location/coordinates` | Город по lat/lon, только если координаты переданы |
| `GET /v2/deliverypoints` | Пункты: `city_code`, `code`, `type`, вес и габариты, `is_handout`, `take_only` |
| `GET /v2/deliverypoints/byPolygons` | **GAP**: нет в предоставленном OpenAPI, не вызывается |

## API Zakopeyki

| Method | Path |
|---|---|
| GET | `/delivery/cdek/regions` |
| GET | `/delivery/cdek/cities?q=` |
| GET | `/delivery/cdek/cities/{code}` |
| GET | `/delivery/cdek/postal-codes?city_code=` |
| GET | `/delivery/cdek/coordinates?latitude=&longitude=` |
| GET | `/delivery/cdek/points?city_code=&delivery_id=&product_id=` |
| GET | `/delivery/cdek/points/{code}` |
| POST | `/delivery/{id}/point-b/validate` |

Ответ не содержит token и `client_secret`. Вес и габариты для фильтра пунктов берутся из shipment заказа или listing, не из query frontend.

## Point B

- Pickup: город → `cdek_city_code` из suggest/cities, пункт → `code` из deliverypoints. Перед сохранением `confirmCity` и `confirmDeliveryPoint`. В получателя пишутся код, название, адрес, город, координаты и индекс из ответа CDEK.
- Door: код пункта не обязателен. Город подтверждается `cities?code=`. Улица проверяется как непустая. Если CDEK вернул индексы города и введённый индекс не входит в список — отказ. Улица API не проверяет.

Изменение Point B по-прежнему вызывает `invalidateQuotes`.

## Cache

Файл `storage/cdek-location-cache`, flock на miss.

| Данные | TTL | Stale при 5xx |
|---|---|---|
| Регионы | 24 ч | да, только для списка |
| Suggest городов | 1 ч | да, только для списка |
| Город по code | 24 ч | нет |
| Индексы | 24 ч | нет |
| Список пунктов | 15 мин | да, для просмотра |
| Подтверждение пункта при сохранении | тот же ключ | **нет** |

Ошибки не кэшируются.

## GAP

1. `byPolygons` отсутствует в OpenAPI.
2. Нет метода проверки улицы/дома. `address_verified` всегда false для door.
3. Смысл query-фильтра `weight_max` в OpenAPI не описан текстом. Дополнительно отбрасываем пункт, если `OfficeDto.weight_max` / `length_max` / `width_max` / `height_max` меньше отправления.
4. `package_count` > 1 в фильтре пунктов OpenAPI нет — не фильтруем по числу мест.
5. Локальная таблица `cdek_delivery_points` остаётся sync-кэшем для других задач и не подтверждает Point B.
