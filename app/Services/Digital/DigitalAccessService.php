<?php

namespace App\Services\Digital;

use App\Helpers\ProductHelper;
use App\Models\DigitalProduct;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;

class DigitalAccessService
{
    private DigitalProduct $digital;
    private Product $products;

    public function __construct(?DigitalProduct $digital = null, ?Product $products = null)
    {
        $this->digital = $digital ?? new DigitalProduct();
        $this->products = $products ?? new Product();
    }

    public function grantFromPaidOrder(int $orderId): void
    {
        $order = (new Order())->find($orderId);
        if (!$order) {
            return;
        }
        $status = (string) ($order['status'] ?? '');
        if (!in_array($status, ['escrowed', 'delivered', 'completed', 'confirmed'], true)
            && empty($order['paid_at'])) {
            return;
        }

        $product = $this->products->find((int) $order['product_id']);
        if (!$product || !ProductHelper::isDigitalListing($product)) {
            return;
        }

        $dp = $this->digital->ensureForListing($product);
        $days = (int) ($dp['access_days'] ?? 365);
        $this->digital->grantAccess(
            (int) $order['buyer_id'],
            (int) $dp['id'],
            $orderId,
            $days
        );

        $this->skipShippingHold($orderId, $order);

        $buyer = (new User())->find((int) $order['buyer_id']);
        if ($buyer) {
            (new Notification())->createFor(
                (int) $buyer['id'],
                t('digital.notify_access', [
                    'title' => (string) ($product['title'] ?? ''),
                    'id' => (string) $orderId,
                ])
            );
        }
    }

    /**
     * @return array{ok: bool, access?: array, digital?: array, product?: array, is_author?: bool, error?: string}
     */
    public function resolveViewer(int $productId, int $userId): array
    {
        $product = $this->products->find($productId);
        if (!$product || !ProductHelper::isDigitalListing($product)) {
            return ['ok' => false, 'error' => t('digital.not_found')];
        }

        $dp = $this->digital->findByProductId($productId);
        if (!$dp) {
            $dp = $this->digital->ensureForListing($product);
        }

        $isAuthor = $userId > 0 && (int) $dp['author_id'] === $userId;
        if ($isAuthor) {
            return ['ok' => true, 'digital' => $dp, 'product' => $product, 'is_author' => true, 'access' => [
                'id' => 0,
                'status' => 'active',
                'access_until' => null,
                'order_id' => null,
            ]];
        }

        $access = $this->digital->findAccess($userId, (int) $dp['id']);
        if ($this->digital->accessIsValid($access)) {
            return [
                'ok' => true,
                'access' => $access,
                'digital' => $dp,
                'product' => $product,
                'is_author' => false,
            ];
        }

        $previewId = (int) ($_GET['lesson_id'] ?? $_POST['lesson_id'] ?? 0);
        if ($previewId > 0) {
            $lesson = $this->digital->findLesson($previewId);
            if ($lesson && (int) $lesson['digital_product_id'] === (int) $dp['id'] && !empty($lesson['is_preview'])) {
                return [
                    'ok' => true,
                    'access' => ['id' => 0, 'status' => 'active', 'access_until' => null, 'order_id' => null],
                    'digital' => $dp,
                    'product' => $product,
                    'is_author' => false,
                    'preview_only' => true,
                    'preview_lesson_id' => $previewId,
                ];
            }
        }

        return ['ok' => false, 'error' => t('digital.no_access'), 'digital' => $dp, 'product' => $product];
    }

    public function watermarkText(array $viewer, array $user): string
    {
        $mode = (string) ($viewer['digital']['watermark_mode'] ?? 'order');
        $name = trim((string) ($user['name'] ?? ''));
        $email = trim((string) ($user['email'] ?? ''));
        $orderId = (int) ($viewer['access']['order_id'] ?? 0);

        return match ($mode) {
            'none' => '',
            'name' => ($name !== '' ? $name : ('ID ' . (int) ($user['id'] ?? 0))) . ' — Zakopeyki.kz',
            'email' => ($email !== '' ? $email : $name) . ' — Zakopeyki.kz',
            default => $orderId > 0
                ? t('digital.wm_order', ['id' => (string) $orderId])
                : (($name !== '' ? $name : ('ID ' . (int) ($user['id'] ?? 0))) . ' — Zakopeyki.kz'),
        };
    }

    /** Курс не едет почтой: доступ сразу, эскроу держим на срок проверки. */
    private function skipShippingHold(int $orderId, array $order): void
    {
        if (($order['status'] ?? '') !== 'escrowed') {
            return;
        }
        $inspect = date('Y-m-d H:i:s', time() + (\App\Services\EscrowService::INSPECT_DAYS * 86400));
        (new Order())->updateFields($orderId, [
            'delivery_method' => 'digital',
            'status' => 'delivered',
            'delivered_at' => date('Y-m-d H:i:s'),
            'inspect_until' => $inspect,
        ]);
    }
}
