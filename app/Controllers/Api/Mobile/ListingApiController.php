<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ProductHelper;
use App\Models\Product;
use App\Models\User;
use App\Services\Listing\ListingService;
use App\Services\MobileApi\Presenter;

/**
 * Объявления продавца в мобильном приложении: форма, свои объявления, создание, правка, удаление.
 * Все правила — ListingService (тот же код, что на сайте).
 *
 * В приложении v1: типы new/used/free/exchange и только самовывоз. Аукционы, платные услуги,
 * биржа услуг и доставка CDEK (точка отправки, габариты) — пока на сайте.
 */
final class ListingApiController extends BaseMobileController
{
    public const APP_TYPES = ['new', 'used', 'free', 'exchange'];

    /** GET /listings/form */
    public function form(): void
    {
        $uid = $this->requireUserId();
        $state = (new ListingService())->formState($uid);
        $this->ok($state + [
            'types' => array_map(static fn (string $t): array => [
                'code' => $t,
                'label' => ProductHelper::label($t),
                'has_price' => in_array($t, ['new', 'used'], true),
                'has_category' => in_array($t, ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true),
                'has_quantity' => ProductHelper::tracksInventory(['type' => $t]),
                'requires_exchange_for' => $t === 'exchange',
            ], self::APP_TYPES),
            'fulfillment_modes' => ['pickup'],
            'web_only_note_url' => ProductHelper::absoluteUrl('/profile?tab=lots'),
        ]);
    }

    /** GET /me/listings */
    public function mine(): void
    {
        $uid = $this->requireUserId();
        $user = (new User())->find($uid) ?? [];
        $rows = array_filter(
            (new Product())->byUser($uid),
            static fn (array $p): bool => !in_array($p['type'] ?? '', ['course', 'gig'], true)
        );
        $items = array_map(static fn (array $p): array => Presenter::card($p + [
            'seller_name' => $user['name'] ?? '',
            'seller_account_type' => $user['account_type'] ?? null,
            'seller_business_status' => $user['business_status'] ?? null,
            'seller_business_name' => $user['business_name'] ?? null,
        ]) + [
            'status' => (string) ($p['status'] ?? ''),
            'editable_in_app' => in_array($p['type'] ?? '', self::APP_TYPES, true),
            'view_count' => (int) ($p['view_count'] ?? 0),
        ], array_values($rows));
        $this->ok(['items' => $items]);
    }

    /** GET /me/listings/{id} — данные для формы редактирования. */
    public function editable(string $id): void
    {
        $uid = $this->requireUserId();
        $p = (new Product())->find((int) $id);
        if (!$p || (int) $p['user_id'] !== $uid) {
            $this->fail('not_found', 404);
        }
        $files = ProductHelper::decodeImages($p);
        $cover = basename((string) ($p['image'] ?? ''));
        $this->ok(['listing' => [
            'id' => (int) $p['id'],
            'type' => (string) $p['type'],
            'editable_in_app' => in_array($p['type'], self::APP_TYPES, true),
            'title' => (string) $p['title'],
            'description' => (string) ($p['description'] ?? ''),
            'characteristics' => (string) ($p['characteristics'] ?? ''),
            'price' => (int) ($p['price'] ?? 0),
            'category' => ($p['category'] ?? '') !== '' ? (string) $p['category'] : null,
            'exchange_for' => ($p['exchange_for'] ?? '') !== '' ? (string) $p['exchange_for'] : null,
            'quantity' => (int) ($p['quantity'] ?? 1),
            'location' => (string) ($p['location'] ?? ''),
            'whatsapp' => ($p['whatsapp'] ?? '') !== '' ? (string) $p['whatsapp'] : null,
            'status' => (string) ($p['status'] ?? ''),
            'images' => array_map(static fn (string $f): array => [
                'file' => $f,
                'url' => Presenter::media(ProductHelper::url('public/uploads/products/' . $f)),
                'is_cover' => $f === $cover,
            ], $files),
        ]]);
    }

    /** POST /listings (multipart: поля + images[]) */
    public function create(): void
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        $input = $this->appInput();
        if (!in_array($input['type'], self::APP_TYPES, true)) {
            $this->fail('type_not_supported_in_app', 422, null, ['type' => self::message('type_not_supported_in_app')]);
        }
        $result = (new ListingService())->create($uid, $input, $_FILES['images'] ?? null, false);
        if (empty($result['ok'])) {
            $this->listingError($result);
        }
        $product = (new Product())->find((int) $result['product_id']);
        $this->ok([
            'product_id' => (int) $result['product_id'],
            'status' => (string) ($product['status'] ?? 'active'),
            'listing_bonus_points' => ($result['bonus'] ?? 0) > 0 ? (int) $result['bonus'] : null,
        ], [], 201);
    }

    /** POST /listings/{id} (multipart: поля + images[] + keep_images[]) */
    public function update(string $id): void
    {
        $uid = $this->requireUserId();
        $existing = (new Product())->find((int) $id);
        if (!$existing || (int) $existing['user_id'] !== $uid) {
            $this->fail('not_found', 404);
        }
        if (!in_array($existing['type'] ?? '', self::APP_TYPES, true)) {
            $this->fail('type_not_supported_in_app', 422);
        }
        $input = $this->appInput();
        if (!in_array($input['type'], self::APP_TYPES, true)) {
            $this->fail('type_not_supported_in_app', 422, null, ['type' => self::message('type_not_supported_in_app')]);
        }
        $result = (new ListingService())->update($uid, (int) $id, $input, $_FILES['images'] ?? null);
        if (empty($result['ok'])) {
            $this->listingError($result);
        }
        $this->ok(['product_id' => (int) $id]);
    }

    /** POST /listings/{id}/delete */
    public function delete(string $id): void
    {
        $uid = $this->requireUserId();
        $result = (new ListingService())->delete($uid, (int) $id);
        if (empty($result['ok'])) {
            $this->listingError($result);
        }
        $this->ok(['deleted' => true]);
    }

    /**
     * Поля формы приложения. Самовывоз фиксирован (доставка CDEK настраивается на сайте).
     *
     * @return array<string, mixed>
     */
    private function appInput(): array
    {
        $body = $this->body();
        $input = [];
        foreach (['type', 'title', 'description', 'characteristics', 'price', 'category', 'exchange_for',
                     'quantity', 'location', 'whatsapp', 'iin', 'bin', 'cover', 'listing_rules_ack'] as $k) {
            if (isset($body[$k]) && is_scalar($body[$k])) {
                $input[$k] = (string) $body[$k];
            }
        }
        $keep = $body['keep_images'] ?? [];
        $input['keep_images'] = is_array($keep) ? array_values(array_filter($keep, 'is_string')) : [];
        $input['type'] = $input['type'] ?? 'used';
        $input['fulfillment_mode'] = 'pickup';

        return $input;
    }

    /** @param array<string, mixed> $result */
    private function listingError(array $result): never
    {
        $code = (string) ($result['code'] ?? 'validation_failed');
        $status = match ($code) {
            'not_found' => 404,
            'aml_blocked', 'limit_reached', 'package_limit' => 403,
            'has_active_orders', 'auction_has_bids' => 409,
            default => 422,
        };
        $field = (string) ($result['field'] ?? '');
        $message = (string) ($result['error'] ?? self::message('validation_failed'));
        $this->fail($code, $status, $message, $field !== '' ? [$field => $message] : []);
    }
}
