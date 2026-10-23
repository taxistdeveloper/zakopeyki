<?php

declare(strict_types=1);

namespace App\Services\Business;

use App\Helpers\ActivityLogger;
use App\Models\CatalogImportJob;
use App\Models\Product;
use App\Services\BusinessPackageService;
use App\Services\Listing\ServiceListingFeeService;
use App\Services\PersonalLimitService;

/**
 * Массовый импорт объявлений. Поля взяты из существующей модели Product / формы профиля.
 * Не исполняет загруженные файлы как код.
 */
final class CatalogImportService
{
    private BusinessPackageService $packages;
    private CatalogImportJob $jobs;
    private Product $products;
    private PersonalLimitService $personalLimits;

    public function __construct(
        ?BusinessPackageService $packages = null,
        ?CatalogImportJob $jobs = null,
        ?Product $products = null,
        ?PersonalLimitService $personalLimits = null
    ) {
        $this->packages = $packages ?? new BusinessPackageService();
        $this->jobs = $jobs ?? new CatalogImportJob();
        $this->products = $products ?? new Product();
        $this->personalLimits = $personalLimits ?? new PersonalLimitService();
    }

    /**
     * @return array{ok: bool, error?: string, code?: string, job_id?: int, status?: string, report?: array}
     */
    public function importXml(int $userId, string $xmlContents, ?string $filename = null): array
    {
        $gate = $this->packages->assertFeature($userId, BusinessPackagePolicy::FEATURE_IMPORT_XML);
        if (!$gate['ok']) {
            return $gate;
        }

        $rate = $this->assertSyncRate($userId, $gate['limits'] ?? []);
        if (!$rate['ok']) {
            return $rate;
        }

        if (strlen($xmlContents) > BusinessPackagePolicy::IMPORT_MAX_BYTES) {
            return ['ok' => false, 'code' => 'file_too_large', 'error' => t('business.err_import_too_large')];
        }

        $rows = CatalogImportParser::parseXml($xmlContents);
        if ($rows === null) {
            return ['ok' => false, 'code' => 'invalid_format', 'error' => t('business.err_import_xml')];
        }

        return $this->runJob($userId, 'xml', $rows, $filename);
    }

    /**
     * @return array{ok: bool, error?: string, code?: string, job_id?: int, status?: string, report?: array}
     */
    public function importCsv(int $userId, string $csvContents, ?string $filename = null): array
    {
        $gate = $this->packages->assertFeature($userId, BusinessPackagePolicy::FEATURE_IMPORT_CSV);
        if (!$gate['ok']) {
            return $gate;
        }

        $rate = $this->assertSyncRate($userId, $gate['limits'] ?? []);
        if (!$rate['ok']) {
            return $rate;
        }

        if (strlen($csvContents) > BusinessPackagePolicy::IMPORT_MAX_BYTES) {
            return ['ok' => false, 'code' => 'file_too_large', 'error' => t('business.err_import_too_large')];
        }

        $rows = CatalogImportParser::parseCsv($csvContents);
        if ($rows === null) {
            return ['ok' => false, 'code' => 'invalid_format', 'error' => t('business.err_import_csv')];
        }

        return $this->runJob($userId, 'csv', $rows, $filename);
    }

    /**
     * Upsert одной позиции через API (поля как у Product).
     *
     * @param array<string, mixed> $item
     * @return array{ok: bool, error?: string, code?: string, product_id?: int, created?: bool}
     */
    public function upsertViaApi(int $userId, array $item): array
    {
        $gate = $this->packages->assertFeature($userId, BusinessPackagePolicy::FEATURE_IMPORT_API);
        if (!$gate['ok']) {
            return $gate;
        }

        $normalized = CatalogImportParser::normalizeRow($item, 0);
        if (!$normalized['ok']) {
            return ['ok' => false, 'code' => 'validation', 'error' => $normalized['error'] ?? t('business.err_import_row')];
        }

        /** @var array<string, mixed> $row */
        $row = $normalized['row'];
        $result = $this->persistRow($userId, $row);
        if (!$result['ok']) {
            return $result;
        }

        ActivityLogger::info('business.import_api', 'API upsert объявления', 'product', (int) $result['product_id'], [
            'external_key' => $row['external_key'] ?? null,
            'created' => $result['created'] ?? false,
        ]);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{ok: bool, error?: string, code?: string, job_id?: int, status?: string, report?: array}
     */
    private function runJob(int $userId, string $source, array $rows, ?string $filename): array
    {
        if (count($rows) > BusinessPackagePolicy::IMPORT_MAX_ROWS) {
            return ['ok' => false, 'code' => 'too_many_rows', 'error' => t('business.err_import_too_many', [
                'n' => (string) BusinessPackagePolicy::IMPORT_MAX_ROWS,
            ])];
        }

        $jobId = $this->jobs->createJob([
            'user_id' => $userId,
            'source' => $source,
            'filename' => $filename,
        ]);

        $this->jobs->updateJob($jobId, [
            'status' => BusinessPackagePolicy::IMPORT_RUNNING,
            'total_rows' => count($rows),
        ]);

        $errors = [];
        $ok = 0;
        $line = 0;
        foreach ($rows as $raw) {
            $line++;
            $normalized = CatalogImportParser::normalizeRow(is_array($raw) ? $raw : [], $line);
            if (!$normalized['ok']) {
                $errors[] = ['line' => $line, 'error' => $normalized['error'] ?? 'invalid'];
                continue;
            }
            $persist = $this->persistRow($userId, $normalized['row']);
            if (!$persist['ok']) {
                $errors[] = ['line' => $line, 'error' => $persist['error'] ?? 'persist', 'code' => $persist['code'] ?? null];
                continue;
            }
            $ok++;
        }

        $errorCount = count($errors);
        $status = BusinessPackagePolicy::IMPORT_COMPLETED;
        if ($ok === 0 && $errorCount > 0) {
            $status = BusinessPackagePolicy::IMPORT_FAILED;
        } elseif ($errorCount > 0) {
            $status = BusinessPackagePolicy::IMPORT_PARTIAL;
        }

        $report = [
            'ok' => $ok,
            'errors' => array_slice($errors, 0, 100),
            'error_total' => $errorCount,
        ];
        $this->jobs->updateJob($jobId, [
            'status' => $status,
            'total_rows' => count($rows),
            'ok_rows' => $ok,
            'error_rows' => $errorCount,
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE),
        ]);

        ActivityLogger::info('business.import_job', 'Импорт каталога', 'catalog_import_job', $jobId, [
            'source' => $source,
            'status' => $status,
            'ok' => $ok,
            'errors' => $errorCount,
        ]);

        return [
            'ok' => $status !== BusinessPackagePolicy::IMPORT_FAILED,
            'job_id' => $jobId,
            'status' => $status,
            'report' => $report,
            'code' => $status === BusinessPackagePolicy::IMPORT_FAILED ? 'import_failed' : null,
            'error' => $status === BusinessPackagePolicy::IMPORT_FAILED ? t('business.err_import_failed') : null,
        ];
    }

    /**
     * @param array<string, int> $limits
     * @return array{ok: bool, error?: string, code?: string}
     */
    private function assertSyncRate(int $userId, array $limits): array
    {
        $perHour = (int) ($limits['sync_per_hour'] ?? 1);
        if ($perHour <= 0) {
            $perHour = 1;
        }
        $since = date('Y-m-d H:i:s', time() - 3600);
        $count = $this->jobs->countJobsSince($userId, $since);
        if ($count >= $perHour) {
            return ['ok' => false, 'code' => 'sync_rate', 'error' => t('business.err_import_rate')];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok: bool, error?: string, code?: string, product_id?: int, created?: bool}
     */
    private function persistRow(int $userId, array $row): array
    {
        $user = ['id' => $userId];
        // PersonalLimit / listing rules остаются обязательными на уровне вызывающего контроллера
        // для web; для API/import проверяем personal publish + package listing caps.
        $limitCheck = $this->personalLimits->assertCanPublish(
            (new \App\Models\User())->find($userId) ?: $user
        );
        if (empty($limitCheck['ok'])) {
            return [
                'ok' => false,
                'code' => 'personal_limit',
                'error' => $limitCheck['error'] ?? t('business.err_generic'),
            ];
        }

        $pkgCheck = $this->packages->assertCanCreateListing($userId);
        // При обновлении существующего по external_key кап новых лотов не применяем
        $externalKey = (string) ($row['external_key'] ?? '');
        $existingId = $externalKey !== '' ? $this->jobs->findProductIdByKey($userId, $externalKey) : null;

        if ($existingId === null && empty($pkgCheck['ok'])) {
            return [
                'ok' => false,
                'code' => 'catalog_cap',
                'error' => $pkgCheck['error'] ?? t('business.err_generic'),
            ];
        }

        $type = (string) ($row['type'] ?? 'used');
        $price = in_array($type, ['free', 'exchange', 'service'], true)
            ? 0
            : (int) ($row['price'] ?? 0);

        $payload = [
            'user_id' => $userId,
            'type' => $type,
            'category' => $row['category'],
            'title' => $row['title'],
            'description' => $row['description'],
            'price' => $price,
            'price_label' => $type === 'service' ? t('price.negotiable') : null,
            'location' => $row['location'],
            'whatsapp' => $row['whatsapp'],
            'exchange_for' => $row['exchange_for'],
            'quantity' => $row['quantity'],
            'images' => [],
        ];

        if ($existingId !== null) {
            $product = $this->products->find($existingId);
            if (!$product || (int) ($product['user_id'] ?? 0) !== $userId) {
                return ['ok' => false, 'code' => 'forbidden', 'error' => t('business.err_import_forbidden')];
            }
            // Смена типа / повторная тарификация при update — не вводим без решения PO (VAS-16.2 Q3).
            $existingImages = [];
            if (!empty($product['images'])) {
                $decoded = json_decode((string) $product['images'], true);
                if (is_array($decoded)) {
                    $existingImages = $decoded;
                }
            }
            $this->products->updateProduct($existingId, array_merge($product, [
                'type' => $payload['type'],
                'category' => $payload['category'],
                'title' => $payload['title'],
                'description' => $payload['description'],
                'price' => $payload['price'],
                'price_label' => $payload['price_label'] ?? ($product['price_label'] ?? null),
                'location' => $payload['location'],
                'whatsapp' => $payload['whatsapp'],
                'exchange_for' => $payload['exchange_for'],
                'quantity' => $payload['quantity'],
                'image' => $product['image'] ?? null,
                'images' => $existingImages,
                'status' => $product['status'] ?? 'active',
            ]));

            return ['ok' => true, 'product_id' => $existingId, 'created' => false];
        }

        if (empty($pkgCheck['ok'])) {
            return [
                'ok' => false,
                'code' => 'catalog_cap',
                'error' => $pkgCheck['error'] ?? t('business.err_generic'),
            ];
        }

        $feeSvc = new ServiceListingFeeService();
        $publishGate = $feeSvc->assertCanPublishNew($type);
        if (empty($publishGate['ok'])) {
            return [
                'ok' => false,
                'code' => 'publish_disabled',
                'error' => $publishGate['error'] ?? t('flash.service_publish_disabled'),
            ];
        }
        $isStaff = $feeSvc->userIsStaff($userId);
        $afford = $feeSvc->assertAffordable($userId, $type, $isStaff);
        if (empty($afford['ok'])) {
            return [
                'ok' => false,
                'code' => 'listing_fee',
                'error' => $afford['error'] ?? t('business.err_generic'),
            ];
        }

        $productId = $this->products->create($payload);
        $charge = $feeSvc->chargeForPublishedProduct($userId, $type, $productId, $isStaff);
        if (empty($charge['ok'])) {
            $this->products->delete($productId);

            return [
                'ok' => false,
                'code' => 'listing_fee',
                'error' => $charge['error'] ?? t('business.err_generic'),
            ];
        }

        $this->packages->consumeListing($userId);
        if ($externalKey !== '') {
            $this->jobs->upsertKey($userId, $externalKey, $productId);
        }

        return ['ok' => true, 'product_id' => $productId, 'created' => true];
    }

}
