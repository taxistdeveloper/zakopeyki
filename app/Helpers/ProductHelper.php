<?php

namespace App\Helpers;

use App\Core\Lang;

class ProductHelper
{
    public const TYPES = [
        'used' => 'Товар Б/У',
        'new' => 'Новый товар',
        'auction' => 'Аукцион',
        'free' => 'Отдам бесплатно',
        'exchange' => 'Обмен',
        'service' => 'Услуги',
        'gig' => 'Биржа услуг',
        'course' => 'Курс',
    ];

    /** Разделы и подкатегории для «Товар Б/У» и «Новый товар» */
    public const PRODUCT_CATEGORY_TREE = [
        'Электроника и бытовая техника' => [
            'Смартфоны и аксессуары',
            'Компьютерная техника',
            'ТВ и видео',
            'Крупная бытовая техника',
            'Мелкая бытовая техника',
            'Фото- и видеотехника',
        ],
        'Транспорт и запчасти' => [
            'Автозапчасти',
            'Автоаксессуары',
            'Мототехника',
            'Велосипеды и самокаты',
        ],
        'Дом и сад' => [
            'Мебель',
            'Интерьер и декор',
            'Строительство и ремонт',
            'Садовый инвентарь',
        ],
        'Одежда, обувь и аксессуары' => [
            'Мужская одежда и обувь',
            'Женская одежда и обувь',
            'Аксессуары',
            'Спортивная одежда',
        ],
        'Детские товары' => [
            'Детская одежда и обувь',
            'Коляски и автокресла',
            'Игрушки и развивающие игры',
            'Товары для кормления и ухода',
        ],
        'Хобби, спорт и отдых' => [
            'Спортивный инвентарь',
            'Книги и журналы',
            'Музыкальные инструменты',
            'Коллекционирование',
            'Туризм и кемпинг',
        ],
        'Животные' => [
            'Аксессуары для животных',
            'Корма и уход',
        ],
    ];

    public const PRODUCT_TYPES_WITH_CATEGORY = ['used', 'new'];

    /** Плата за публикацию объявления в разделе «Услуги» (KZT). */
    public const SERVICE_LISTING_FEE = 100;

    public static function label(string $type): string
    {
        $key = 'types.' . $type;
        $translated = Lang::get($key);
        if ($translated !== $key) {
            return $translated;
        }

        return self::TYPES[$type] ?? $type;
    }

    public static function categoryLabel(string $name): string
    {
        return Lang::category($name);
    }

    /** Плоский список всех допустимых значений category (раздел / подкатегория). */
    public static function allProductCategories(): array
    {
        $out = [];
        foreach (self::PRODUCT_CATEGORY_TREE as $parent => $children) {
            foreach ($children as $child) {
                $out[] = self::formatCategory($parent, $child);
            }
        }
        return $out;
    }

    public static function formatCategory(string $parent, string $child): string
    {
        return $parent . ' / ' . $child;
    }

    /** Разбирает сохранённое значение category в [parent, child]. */
    public static function parseCategory(?string $category): array
    {
        $category = trim((string) $category);
        $defaultParent = array_key_first(self::PRODUCT_CATEGORY_TREE);
        $defaultChild = self::PRODUCT_CATEGORY_TREE[$defaultParent][0];

        if ($category === '') {
            return [$defaultParent, $defaultChild];
        }

        if (str_contains($category, ' / ')) {
            [$parent, $child] = explode(' / ', $category, 2);
            if (isset(self::PRODUCT_CATEGORY_TREE[$parent])
                && in_array($child, self::PRODUCT_CATEGORY_TREE[$parent], true)) {
                return [$parent, $child];
            }
        }

        // Старый формат или только название подкатегории
        foreach (self::PRODUCT_CATEGORY_TREE as $parent => $children) {
            if ($category === $parent) {
                return [$parent, $children[0]];
            }
            if (in_array($category, $children, true)) {
                return [$parent, $category];
            }
            // частичное совпадение со старыми короткими названиями
            foreach ($children as $child) {
                if (str_starts_with($child, $category) || str_starts_with($category, $child)) {
                    return [$parent, $child];
                }
            }
        }

        return [$defaultParent, $defaultChild];
    }

    public static function normalizeCategory(?string $category, string $type): string
    {
        if (!in_array($type, self::PRODUCT_TYPES_WITH_CATEGORY, true)) {
            return 'Разное';
        }
        [$parent, $child] = self::parseCategory($category);
        return self::formatCategory($parent, $child);
    }

    /** Продавец объявления — верифицированный бизнес-аккаунт. */
    public static function sellerIsBusiness(array $item): bool
    {
        $type = (string) ($item['seller_account_type'] ?? $item['account_type'] ?? '');
        $status = (string) ($item['seller_business_status'] ?? $item['business_status'] ?? '');
        return $type === 'business' && $status === 'verified';
    }

    /** Подпись ИП/ТОО: «ИП Иванов» или «ТОО Сети». */
    public static function sellerBusinessLabel(array $item): string
    {
        if (!self::sellerIsBusiness($item)) {
            return '';
        }
        $name = trim((string) ($item['seller_business_name'] ?? $item['business_name'] ?? ''));
        $entity = strtolower((string) ($item['seller_business_entity_type'] ?? $item['business_entity_type'] ?? ''));
        $prefix = match ($entity) {
            'too' => (string) t('business.entity_too'),
            'ip' => (string) t('business.entity_ip'),
            default => '',
        };
        if ($name === '') {
            return $prefix;
        }
        $upper = mb_strtoupper($name);
        if ($prefix !== '' && (str_starts_with($upper, 'ИП ') || str_starts_with($upper, 'ТОО ')
            || str_starts_with($upper, 'ЖК ') || str_starts_with($upper, 'ЖШС '))) {
            return $name;
        }
        return $prefix !== '' ? ($prefix . ' ' . $name) : $name;
    }

    public static function badge(string $type): array
    {
        $classes = [
            'used' => 'bg-orange-500 text-white',
            'new' => 'bg-blue-600 text-white',
            'auction' => 'bg-red-500 text-white',
            'free' => 'bg-sky-500 text-white',
            'exchange' => 'bg-indigo-500 text-white',
            'service' => 'bg-emerald-500 text-white',
            'gig' => 'bg-teal-600 text-white',
            'course' => 'bg-blue-500 text-white',
        ];

        $key = 'badge.' . $type;
        $text = Lang::get($key);
        if ($text === $key) {
            $text = $type;
        }

        return [
            'text' => $text,
            'class' => $classes[$type] ?? 'bg-amber-500 text-white',
        ];
    }

    public static function icon(string $type, string $class = 'w-10 h-10 text-brand-500/80'): string
    {
        return IconHelper::type($type, $class);
    }

    public static function decodeImages(?array $item): array
    {
        if (!$item) {
            return [];
        }

        $files = [];
        if (!empty($item['images'])) {
            $decoded = json_decode((string) $item['images'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $file) {
                    if (is_string($file) && $file !== '') {
                        $files[] = basename($file);
                    }
                }
            }
        }

        if (!$files && !empty($item['image'])) {
            $files[] = basename((string) $item['image']);
        }

        return array_values(array_unique(array_slice($files, 0, 3)));
    }

    public static function imageUrl(?array $item): ?string
    {
        if (!$item) {
            return null;
        }

        $files = self::decodeImages($item);
        $cover = !empty($item['image']) ? basename((string) $item['image']) : null;
        if ($cover && in_array($cover, $files, true)) {
            return self::url('public/uploads/products/' . $cover);
        }
        if (!empty($files[0])) {
            return self::url('public/uploads/products/' . $files[0]);
        }
        return null;
    }

    /** @return list<string> */
    public static function imageUrls(?array $item): array
    {
        $files = self::decodeImages($item);
        if (!$files) {
            return [];
        }

        $cover = !empty($item['image']) ? basename((string) $item['image']) : null;
        if ($cover && in_array($cover, $files, true)) {
            $files = array_values(array_unique(array_merge([$cover], $files)));
        }

        $urls = [];
        foreach ($files as $file) {
            $urls[] = self::url('public/uploads/products/' . $file);
        }
        return $urls;
    }

    /** Типы объявлений, которые можно оплатить на платформе. */
    public const PURCHASABLE_TYPES = ['used', 'new', 'gig', 'course'];

    public static function isPurchasable(array $item): bool
    {
        if (($item['status'] ?? 'active') !== 'active') {
            return false;
        }
        if (!in_array($item['type'] ?? '', self::PURCHASABLE_TYPES, true)) {
            return false;
        }
        return (int) ($item['price'] ?? 0) > 0;
    }

    public static function checkoutUrl(int|string $productId): string
    {
        return self::url('/checkout/' . (int) $productId);
    }

    public static function cartCheckoutUrl(): string
    {
        return self::url('/checkout/cart');
    }

    public static function formatPrice(array $item): string
    {
        if ($item['type'] === 'auction') {
            $price = (int) ($item['calculated_current_price'] ?? ($item['current_bid'] ?: $item['price']));
            return Lang::getf('price.bid', [
                'amount' => number_format($price, 0, '', ' '),
            ]);
        }

        if ($item['type'] === 'free') {
            return Lang::get('price.free');
        }

        if ($item['type'] === 'exchange') {
            return Lang::get('price.exchange');
        }

        if ($item['type'] === 'service') {
            return Lang::get('price.negotiable');
        }

        if ((int) $item['price'] === 0) {
            return Lang::get('price.free');
        }

        if (!empty($item['price_label'])) {
            return $item['price_label'];
        }

        return number_format((int) $item['price'], 0, '', ' ') . ' ₸';
    }

    public static function url(string $path = ''): string
    {
        $path = trim($path);

        // Уже полный URL — не склеиваем с базой
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $base = rtrim((string) ($GLOBALS['appConfig']['url'] ?? ''), '/');

        // Только путь (/zakapeiku), без http://
        if (preg_match('#^https?://#i', $base)) {
            $base = rtrim((string) (parse_url($base, PHP_URL_PATH) ?: ''), '/');
        }

        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }

        return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
    }

    public static function absoluteUrl(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'zakopeyki.kz');

        return $scheme . '://' . $host . self::url($path);
    }

    /** @return array{whatsapp: string, telegram: string, url: string} */
    public static function shareLinks(array $item): array
    {
        $publicUrl = self::absoluteUrl('/product/' . (int) ($item['id'] ?? 0));
        $title = trim((string) ($item['title'] ?? ''));
        $text = $title !== '' ? $title . "\n" . $publicUrl : $publicUrl;

        return [
            'url' => $publicUrl,
            'whatsapp' => 'https://wa.me/?text=' . rawurlencode($text),
            'telegram' => 'https://t.me/share/url?url=' . rawurlencode($publicUrl) . '&text=' . rawurlencode($title),
        ];
    }

    /** Международный номер только цифрами для wa.me, либо null. */
    public static function whatsappDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }

        if (!preg_match('/^\d{10,15}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    /** @return array{ok: bool, value: ?string} */
    public static function normalizeWhatsappInput(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['ok' => true, 'value' => null];
        }

        $digits = self::whatsappDigits($raw);
        if ($digits === null) {
            return ['ok' => false, 'value' => null];
        }

        return ['ok' => true, 'value' => $digits];
    }

    public static function formatWhatsappInput(?string $digits): string
    {
        $digits = self::whatsappDigits((string) $digits);
        if ($digits === null) {
            return '';
        }

        return '+' . $digits;
    }
}
