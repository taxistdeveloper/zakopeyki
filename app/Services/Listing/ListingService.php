<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Helpers\UploadHelper;
use App\Models\Follow;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AMLService;
use App\Services\BusinessPackageService;
use App\Services\PersonalLimitService;

/**
 * Создание, редактирование и удаление объявлений (товары/услуги/бартер/даром/аукцион).
 * Единая логика для сайта (ProfileController) и мобильного API — правила не дублируются.
 *
 * Вход — массив полей формы (те же ключи, что POST на сайте) и массив загруженных файлов
 * в формате $_FILES['images'] (name[], tmp_name[], error[], size[]).
 * Ошибка: ['ok' => false, 'code' => машинный код, 'error' => локализованный текст, 'field'?, ...].
 */
final class ListingService
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    /** Типы, которые создаёт этот сервис (gig — отдельный поток биржи услуг, course — студия курсов). */
    public const TYPES = ['new', 'used', 'auction', 'service', 'exchange', 'free'];

    /**
     * @param \Closure(string, string, list<string>): bool|null $uploadValidator
     *   только для CLI-тестов: в HTTP всегда UploadHelper::isAllowedUpload (включая is_uploaded_file)
     */
    public function __construct(private ?\Closure $uploadValidator = null)
    {
        if ($uploadValidator !== null && PHP_SAPI !== 'cli') {
            throw new \LogicException('uploadValidator override is allowed in CLI tests only');
        }
    }

    /**
     * Состояние формы для клиента: что нужно сделать до публикации.
     *
     * @return array<string, mixed>
     */
    public function formState(int $userId): array
    {
        $user = (new User())->find($userId) ?? [];
        $limit = (new PersonalLimitService())->assertCanPublish($user);
        $pkg = (new BusinessPackageService())->assertCanCreateListing($userId);
        $isBusiness = AMLService::isBusinessUser($user);
        $taxId = (string) ($isBusiness ? ($user['bin'] ?? '') : ($user['iin'] ?? ''));

        return [
            'rules_accepted' => !empty($user['listing_rules_accepted_at']),
            'tax_id_kind' => $isBusiness ? 'bin' : 'iin',
            // при отключённых AML-проверках (локальная разработка) ИИН/БИН не запрашивается
            'tax_id_on_file' => preg_match('/^\d{12}$/', $taxId) === 1 || AMLService::skipChecks(),
            'max_photos' => (new BusinessPackageService())->maxPhotosForUser($userId),
            'max_photo_bytes' => self::MAX_IMAGE_BYTES,
            'can_publish' => !empty($limit['ok']) && !empty($pkg['ok']),
            'publish_block_reason' => empty($limit['ok'])
                ? ($limit['error'] ?? t('business.err_limit_reached_short'))
                : (empty($pkg['ok']) ? ($pkg['error'] ?? t('business.err_generic')) : null),
            'default_location' => (string) ($user['ship_city'] ?? '') !== '' ? (string) $user['ship_city'] : 'Караганда',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $files $_FILES['images']-формат
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input, ?array $files, bool $isStaff = false): array
    {
        $type = (string) ($input['type'] ?? 'new');
        if (!in_array($type, self::TYPES, true) || !isset(ProductHelper::marketplaceTypes()[$type])) {
            return self::fail('invalid_type', t('mobile_api.invalid_value'), 'type');
        }

        $users = new User();
        $user = $users->find($userId);
        if (!$user) {
            return self::fail('not_found', t('flash.lot_not_found'));
        }
        if (empty($user['listing_rules_accepted_at'])) {
            if ((string) ($input['listing_rules_ack'] ?? '') !== '1' && ($input['listing_rules_ack'] ?? null) !== true) {
                return self::fail('rules_required', t('flash.listing_rules_required'), 'listing_rules_ack');
            }
            $users->acceptListingRules($userId);
        }

        $aml = AMLService::make()->screenUser($userId, (string) ($input['bin'] ?? $input['iin'] ?? ''), 'listing');
        if (empty($aml['ok'])) {
            return self::fail(!empty($aml['blocked']) ? 'aml_blocked' : 'tax_id_invalid', (string) ($aml['error'] ?? t('flash.aml_blocked')), 'iin');
        }
        $user = $users->find($userId) ?? $user;

        $limitCheck = (new PersonalLimitService())->assertCanPublish($user);
        if (empty($limitCheck['ok'])) {
            return self::fail('limit_reached', (string) ($limitCheck['error'] ?? t('business.err_limit_reached_short')));
        }
        $pkgService = new BusinessPackageService();
        $pkgCheck = $pkgService->assertCanCreateListing($userId);
        if (empty($pkgCheck['ok'])) {
            return self::fail('package_limit', (string) ($pkgCheck['error'] ?? t('business.err_generic')));
        }

        $fields = $this->validateCommon($type, $input, null);
        if (empty($fields['ok'])) {
            return $fields;
        }

        $listingFeeSvc = new ServiceListingFeeService();
        $publishGate = $listingFeeSvc->assertCanPublishNew($type);
        if (empty($publishGate['ok'])) {
            return self::fail('service_publish_disabled', (string) ($publishGate['error'] ?? t('flash.service_publish_disabled')));
        }
        $afford = $listingFeeSvc->assertAffordable($userId, $type, $isStaff);
        if (empty($afford['ok'])) {
            return self::fail('service_fee_unaffordable', (string) ($afford['error'] ?? t('flash.service_fee_need', [
                'amount' => Wallet::formatMoney($listingFeeSvc->feeForType($type)),
            ])));
        }
        $serviceFee = (int) ($afford['fee'] ?? 0);

        $resolved = $this->resolveImages($userId, null, $input, $files, true, $pkgService->maxPhotosForUser($userId));
        if (!empty($resolved['error'])) {
            return self::fail('photo_error', (string) $resolved['error'], 'images');
        }

        $shippingSvc = new ListingShippingService();
        $shipResult = $shippingSvc->validateAndBuild($userId, $type, $input, $user);
        if (empty($shipResult['ok'])) {
            return self::fail('shipping_invalid', (string) ($shipResult['error'] ?? t('listing_shipping.validation_failed')), (string) ($shipResult['field'] ?? ''), [
                'missing_fields' => $shipResult['missing_fields'] ?? [],
                'shipping_error_code' => $shipResult['error_code'] ?? null,
            ]);
        }

        $products = new Product();
        $productId = (int) $products->create(array_merge($fields['row'], [
            'user_id' => $userId,
            'image' => $resolved['cover'],
            'images' => $resolved['images'],
        ], $fields['auction']));
        $products->setTryOnType($productId, \App\Services\TryOn\TryOnTypes::normalizeForListing(
            isset($input['tryon_type']) ? (string) $input['tryon_type'] : null,
            $type,
            (string) $fields['row']['category'],
            null
        ));

        if (!empty($shipResult['data'])) {
            $save = $shippingSvc->saveForOwnedProduct($productId, $userId, $shipResult['data']);
            if (empty($save['ok'])) {
                $products->delete($productId);

                return self::fail('shipping_save_failed', (string) ($save['error'] ?? t('listing_shipping.validation_failed')));
            }
        }

        if ($serviceFee > 0) {
            $pay = $listingFeeSvc->chargeForPublishedProduct($userId, $type, $productId, $isStaff);
            if (empty($pay['ok'])) {
                $products->deleteProductFiles($resolved['images'] ?? []);
                $products->delete($productId);
                $payErr = (string) ($pay['error'] ?? '');

                return self::fail('service_fee_failed', $payErr !== '' ? $payErr : t('flash.service_fee_failed'));
            }
            $serviceFee = (int) ($pay['fee'] ?? $serviceFee);
        }

        $title = (string) $fields['row']['title'];
        ActivityLogger::info('product.create', 'Добавлено объявление «' . $title . '»', 'product', $productId, [
            'type' => $type,
            'price' => $fields['row']['price'],
            'listing_fee' => $serviceFee,
        ]);
        $pkgService->consumeListing($userId);
        $bonusResult = (new \App\Models\Bonus())->awardListing($userId, $productId);
        $shortTitle = mb_strlen($title) > 80 ? mb_substr($title, 0, 77) . '…' : $title;
        (new Follow())->notifyFollowers($userId, t('seller.notify_product', [
            'name' => (string) ($user['name'] ?? 'Продавец'),
            'title' => $shortTitle,
        ]));

        $bonus = (!empty($bonusResult['ok']) && empty($bonusResult['skipped'])) ? (int) ($bonusResult['amount'] ?? 0) : 0;

        return ['ok' => true, 'product_id' => $productId, 'fee' => $serviceFee, 'bonus' => $bonus];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $files
     * @return array<string, mixed>
     */
    public function update(int $userId, int $productId, array $input, ?array $files): array
    {
        $products = new Product();
        $product = $products->find($productId);
        if (!$product || (int) $product['user_id'] !== $userId) {
            return self::fail('not_found', t('flash.lot_not_found'));
        }

        $type = (string) ($input['type'] ?? $product['type']);
        if (!isset(ProductHelper::marketplaceTypes()[$type]) || $type === 'gig') {
            $type = isset(ProductHelper::marketplaceTypes()[$product['type'] ?? '']) ? (string) $product['type'] : 'used';
        }
        if ($type === 'gig') {
            return self::fail('gig_not_editable', t('gigs.err_edit_as_product'));
        }

        $fields = $this->validateCommon($type, $input, $product);
        if (empty($fields['ok'])) {
            return $fields;
        }

        $resolved = $this->resolveImages($userId, $product, $input, $files, true, null);
        if (!empty($resolved['error'])) {
            return self::fail('photo_error', (string) $resolved['error'], 'images');
        }

        $shippingSvc = new ListingShippingService();
        $shipResult = $shippingSvc->validateAndBuild($userId, $type, $input, (new User())->find($userId));
        if (empty($shipResult['ok'])) {
            return self::fail('shipping_invalid', (string) ($shipResult['error'] ?? t('listing_shipping.validation_failed')), (string) ($shipResult['field'] ?? ''), [
                'missing_fields' => $shipResult['missing_fields'] ?? [],
                'shipping_error_code' => $shipResult['error_code'] ?? null,
            ]);
        }

        $products->updateProduct($productId, array_merge($fields['row'], [
            'image' => $resolved['cover'],
            'images' => $resolved['images'],
            'status' => $product['status'] ?? 'active',
        ], $fields['auction']));
        $products->setTryOnType($productId, \App\Services\TryOn\TryOnTypes::normalizeForListing(
            isset($input['tryon_type']) ? (string) $input['tryon_type'] : null,
            $type,
            (string) $fields['row']['category'],
            isset($product['tryon_type']) ? (string) $product['tryon_type'] : null
        ));

        if (!empty($shipResult['data'])) {
            $save = $shippingSvc->saveForOwnedProduct($productId, $userId, $shipResult['data']);
            if (empty($save['ok'])) {
                return self::fail('shipping_save_failed', (string) ($save['error'] ?? t('listing_shipping.validation_failed')));
            }
        }

        ActivityLogger::info('product.update', 'Обновлено объявление «' . $fields['row']['title'] . '»', 'product', $productId, ['type' => $type]);

        return ['ok' => true, 'product_id' => $productId];
    }

    /** @return array<string, mixed> */
    public function delete(int $userId, int $productId): array
    {
        $products = new Product();
        $product = $products->find($productId);
        if (!$product || (int) $product['user_id'] !== $userId) {
            return self::fail('not_found', t('flash.lot_not_found'));
        }
        if ((new Order())->hasActiveOrdersForProduct($productId)) {
            return self::fail('has_active_orders', t('listing.err_active_orders'));
        }
        if (($product['type'] ?? '') === 'auction' && ($product['status'] ?? '') === 'active' && $products->countBids($productId) > 0) {
            return self::fail('auction_has_bids', t('listing.err_auction_bids'));
        }
        $products->deleteProductFiles(ProductHelper::decodeImages($product));
        $products->delete($productId);
        ActivityLogger::info('product.delete', 'Удалено объявление «' . ($product['title'] ?? '') . '»', 'product', $productId);

        return ['ok' => true];
    }

    // ---------------------------------------------------------------- общие проверки полей

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validateCommon(string $type, array $input, ?array $existing): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($title === '' || $description === '') {
            return self::fail('title_desc_required', t('flash.title_desc_required'), $title === '' ? 'title' : 'description');
        }

        $characteristics = ProductHelper::normalizeCharacteristics((string) ($input['characteristics'] ?? ''));
        if ($characteristics === null) {
            return self::fail('characteristics_invalid', t('profile.characteristics_invalid'), 'characteristics');
        }

        $exchangeFor = trim((string) ($input['exchange_for'] ?? ''));
        if ($type === 'exchange' && $exchangeFor === '') {
            return self::fail('exchange_for_required', t('flash.exchange_for_required'), 'exchange_for');
        }
        if ($type !== 'exchange') {
            $exchangeFor = '';
        }

        $noPrice = in_array($type, ['free', 'exchange', 'service'], true);
        $price = $noPrice ? 0 : ($input['price'] ?? ($existing['price'] ?? 0));
        $priceLabel = match ($type) {
            'free' => 'Бесплатно',
            'exchange' => 'Обмен',
            'service' => t('price.negotiable'),
            default => null,
        };

        $auction = (new AuctionListingFields())->fromInput($type, (int) preg_replace('/\D/', '', (string) $price), $input, $existing);
        if (!empty($auction['error'])) {
            return self::fail('auction_invalid', (string) $auction['error'], 'auction');
        }

        $whatsapp = ProductHelper::normalizeWhatsappInput((string) ($input['whatsapp'] ?? ''));
        if (!$whatsapp['ok']) {
            return self::fail('whatsapp_invalid', t('profile.whatsapp_invalid'), 'whatsapp');
        }

        $category = ProductHelper::normalizeCategory(isset($input['category']) ? (string) $input['category'] : ($existing['category'] ?? null), $type);

        $quantity = $existing ? max(1, (int) ($existing['quantity'] ?? 1)) : 1;
        if (ProductHelper::tracksInventory(['type' => $type])) {
            $parsed = ProductHelper::parseStockQuantity($input['quantity'] ?? $quantity);
            if (!$parsed['ok']) {
                return self::fail('quantity_invalid', (string) ($parsed['error'] ?? t('flash.quantity_invalid')), 'quantity');
            }
            $quantity = $parsed['value'];
        }

        $location = trim((string) ($input['location'] ?? ($existing['location'] ?? 'Караганда')));

        return [
            'ok' => true,
            'row' => [
                'type' => $type,
                'category' => $category,
                'title' => $title,
                'description' => $description,
                'characteristics' => $characteristics,
                'price' => $price,
                'exchange_for' => $exchangeFor !== '' ? mb_substr($exchangeFor, 0, 255) : null,
                'price_label' => $priceLabel,
                'location' => $location !== '' ? mb_substr($location, 0, 120) : 'Караганда',
                'whatsapp' => $whatsapp['value'],
                'quantity' => $quantity,
            ],
            'auction' => $auction['fields'],
        ];
    }

    // ---------------------------------------------------------------- фото

    /**
     * @param array<string, mixed>|null $existingProduct
     * @param array<string, mixed> $input keep_images[], cover
     * @param array<string, mixed>|null $files
     * @return array{images?: list<string>, cover?: string, error?: string}
     */
    public function resolveImages(int $userId, ?array $existingProduct, array $input, ?array $files, bool $required, ?int $maxPhotos): array
    {
        $products = new Product();
        $oldFiles = $existingProduct ? ProductHelper::decodeImages($existingProduct) : [];
        $max = max(1, $maxPhotos ?? (new BusinessPackageService())->maxPhotosForUser($userId));

        $keep = $input['keep_images'] ?? [];
        if (!is_array($keep)) {
            $keep = [];
        }
        $kept = [];
        foreach ($keep as $file) {
            $name = basename((string) $file);
            if ($name !== '' && in_array($name, $oldFiles, true) && !in_array($name, $kept, true)) {
                $kept[] = $name;
            }
        }

        $slotsLeft = $max - count($kept);
        $uploaded = $slotsLeft > 0 ? $this->uploadImages($userId, $files, $slotsLeft) : ['files' => []];
        if (!empty($uploaded['error'])) {
            return ['error' => $uploaded['error']];
        }

        $images = array_values(array_slice(array_merge($kept, $uploaded['files'] ?? []), 0, $max));
        if (!$images) {
            if (!$required) {
                return ['images' => [], 'cover' => ''];
            }

            return ['error' => t('flash.need_photo_n', ['n' => (string) $max])];
        }

        // Удаляем старые файлы только после успешной загрузки новых
        $removed = array_diff($oldFiles, $kept);
        if ($removed) {
            $products->deleteProductFiles(array_values($removed));
        }

        $coverRaw = trim((string) ($input['cover'] ?? ''));
        $cover = null;
        if (str_starts_with($coverRaw, '__new__')) {
            $cover = ($uploaded['files'] ?? [])[(int) substr($coverRaw, 7)] ?? null;
        } elseif ($coverRaw !== '' && in_array(basename($coverRaw), $images, true)) {
            $cover = basename($coverRaw);
        }
        if (!$cover || !in_array($cover, $images, true)) {
            $cover = $images[0];
        }

        return ['images' => $images, 'cover' => $cover];
    }

    /**
     * @param array<string, mixed>|null $files
     * @return array{files?: list<string>, error?: string}
     */
    private function uploadImages(int $userId, ?array $files, int $max): array
    {
        if ($max < 1 || empty($files) || !is_array($files['name'] ?? null)) {
            return ['files' => []];
        }
        $dir = dirname(__DIR__, 3) . '/public/uploads/products';
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || !is_writable($dir)) {
            return ['error' => t('flash.photo_save_fail')];
        }

        $saved = [];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if (count($saved) >= $max) {
                break;
            }
            $name = (string) ($files['name'][$i] ?? '');
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($name === '' || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                return ['error' => t('flash.upload_error')];
            }
            if ((int) ($files['size'][$i] ?? 0) > self::MAX_IMAGE_BYTES) {
                return ['error' => t('flash.photo_too_big')];
            }
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = $this->uploadValidator !== null
                ? ($this->uploadValidator)($tmp, $name, self::IMAGE_EXT)
                : UploadHelper::isAllowedUpload($tmp, $name, self::IMAGE_EXT);
            if (!in_array($ext, self::IMAGE_EXT, true) || !$allowed) {
                return ['error' => t('flash.photo_formats')];
            }
            $filename = 'product_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . UploadHelper::normalizeExt($name);
            $dest = $dir . '/' . $filename;
            if (!$this->moveUploaded($tmp, $dest)) {
                return ['error' => t('flash.photo_save_fail')];
            }
            UploadHelper::applyWatermark($dest);
            $saved[] = $filename;
        }

        return ['files' => $saved];
    }

    /** move_uploaded_file для HTTP; в CLI-тестах — rename (is_uploaded_file там всегда false). */
    private function moveUploaded(string $tmp, string $dest): bool
    {
        if (PHP_SAPI === 'cli' && is_file($tmp)) {
            return @rename($tmp, $dest);
        }

        return @move_uploaded_file($tmp, $dest);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function fail(string $code, string $message, string $field = '', array $extra = []): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $message] + ($field !== '' ? ['field' => $field] : []) + $extra;
    }
}
