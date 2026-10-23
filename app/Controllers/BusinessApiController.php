<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Helpers\ActivityLogger;
use App\Models\CatalogImportJob;
use App\Models\SellerApiKey;
use App\Services\Business\BusinessManagerRequestService;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\Business\CatalogImportService;
use App\Services\Business\SellerAnalyticsService;
use App\Services\BusinessPackageService;

/**
 * Серверные API бизнес-пакета (без UI). Session или Bearer seller API key.
 */
class BusinessApiController extends BaseApiController
{
    public function featureStatus(): void
    {
        $userId = $this->requireUserId();
        $svc = new BusinessPackageService();
        $sub = $svc->activeSubscription($userId);
        $this->jsonResponse(true, [
            'sales_enabled' => $svc->isSalesEnabled(),
            'features_enabled' => $svc->isFeaturesEnabled(),
            'ai_addon_sales_enabled' => $svc->isAiAddonSalesEnabled(),
            'ai_addon_sales' => $svc->aiAddonSalesFlags(),
            'has_active_package' => $sub !== null,
            'subscription' => $sub ? [
                'id' => (int) $sub['id'],
                'package_id' => (int) $sub['package_id'],
                'package_slug' => $sub['package_slug'] ?? null,
                'starts_at' => $sub['starts_at'] ?? null,
                'ends_at' => $sub['ends_at'] ?? null,
                'status' => $sub['status'] ?? null,
            ] : null,
            'usage' => $svc->usageSnapshot($userId),
            'payments' => $svc->paymentHistory($userId, 20),
        ]);
    }

    public function purchase(): void
    {
        $userId = $this->requireSessionUser();
        Csrf::enforce();

        $input = array_merge($_POST, $this->getJsonInput());
        $packageId = (int) ($input['package_id'] ?? 0);
        $idempotency = isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null;

        $result = (new BusinessPackageService())->purchase($userId, $packageId, $idempotency, $input);
        if (empty($result['ok'])) {
            $code = (string) ($result['code'] ?? '');
            $http = in_array($code, ['sales_disabled', 'ai_addon_sales_disabled', 'business_required', 'need_package', 'features_disabled', 'need_plan'], true)
                ? 403
                : 400;
            $this->jsonResponse(false, ['code' => $code !== '' ? $code : null], $result['error'] ?? t('business.err_generic'), $http);
        }

        $this->jsonResponse(true, [
            'subscription_id' => $result['subscription_id'] ?? null,
            'payment_id' => $result['payment_id'] ?? null,
            'idempotent' => !empty($result['idempotent']),
        ]);
    }

    public function importXml(): void
    {
        $userId = $this->requireUserId();
        $contents = $this->readUploadedOrBody('file', 'text/xml,application/xml');
        if ($contents === null) {
            $this->jsonResponse(false, null, t('business.err_import_empty'), 400);
        }
        $filename = $_FILES['file']['name'] ?? 'upload.xml';
        $result = (new CatalogImportService())->importXml($userId, $contents, (string) $filename);
        $this->respondImport($result);
    }

    public function importCsv(): void
    {
        $userId = $this->requireUserId();
        $contents = $this->readUploadedOrBody('file', 'text/csv,text/plain');
        if ($contents === null) {
            $this->jsonResponse(false, null, t('business.err_import_empty'), 400);
        }
        $filename = $_FILES['file']['name'] ?? 'upload.csv';
        $result = (new CatalogImportService())->importCsv($userId, $contents, (string) $filename);
        $this->respondImport($result);
    }

    public function importJob(string $id): void
    {
        $userId = $this->requireUserId();
        $job = (new CatalogImportJob())->findForUser((int) $id, $userId);
        if (!$job) {
            $this->jsonResponse(false, null, t('business.err_import_job'), 404);
        }
        $report = null;
        if (!empty($job['report_json'])) {
            $decoded = json_decode((string) $job['report_json'], true);
            $report = is_array($decoded) ? $decoded : null;
        }
        $this->jsonResponse(true, [
            'job' => [
                'id' => (int) $job['id'],
                'status' => $job['status'],
                'source' => $job['source'],
                'total_rows' => (int) $job['total_rows'],
                'ok_rows' => (int) $job['ok_rows'],
                'error_rows' => (int) $job['error_rows'],
                'report' => $report,
            ],
        ]);
    }

    public function catalogUpsert(): void
    {
        $userId = $this->requireApiUser();
        $item = $this->getJsonInput();
        $result = (new CatalogImportService())->upsertViaApi($userId, $item);
        if (empty($result['ok'])) {
            $code = ($result['code'] ?? '') === 'need_package' || ($result['code'] ?? '') === 'features_disabled' ? 403 : 400;
            $this->jsonResponse(false, ['code' => $result['code'] ?? null], $result['error'] ?? t('business.err_generic'), $code);
        }
        $this->jsonResponse(true, [
            'product_id' => $result['product_id'] ?? null,
            'created' => !empty($result['created']),
        ]);
    }

    public function createApiKey(): void
    {
        $userId = $this->requireSessionUser();
        Csrf::enforce();

        $gate = (new BusinessPackageService())->assertFeature($userId, BusinessPackagePolicy::FEATURE_IMPORT_API);
        if (empty($gate['ok'])) {
            $this->jsonResponse(false, ['code' => $gate['code'] ?? null], $gate['error'] ?? t('business.err_generic'), 403);
        }

        $input = array_merge($_POST, $this->getJsonInput());
        $name = trim((string) ($input['name'] ?? 'default'));
        $created = (new SellerApiKey())->createForUser($userId, $name !== '' ? $name : 'default');
        if (empty($created['ok'])) {
            $this->jsonResponse(false, null, t('business.err_api_key'), 400);
        }

        ActivityLogger::info('business.api_key_created', 'Создан API-ключ продавца', 'seller_api_key', (int) $created['id'], [
            'user_id' => $userId,
            'prefix' => $created['prefix'] ?? null,
        ]);

        $this->jsonResponse(true, [
            'id' => $created['id'],
            'prefix' => $created['prefix'],
            // plain_key отдаётся один раз
            'api_key' => $created['plain_key'],
        ]);
    }

    public function listApiKeys(): void
    {
        $userId = $this->requireSessionUser();
        $gate = (new BusinessPackageService())->assertFeature($userId, BusinessPackagePolicy::FEATURE_IMPORT_API);
        if (empty($gate['ok'])) {
            $this->jsonResponse(false, ['code' => $gate['code'] ?? null], $gate['error'] ?? t('business.err_generic'), 403);
        }
        $this->jsonResponse(true, [
            'keys' => (new SellerApiKey())->listActiveForUser($userId),
        ]);
    }

    public function revokeApiKey(string $id): void
    {
        $userId = $this->requireSessionUser();
        Csrf::enforce();
        $ok = (new SellerApiKey())->revoke((int) $id, $userId);
        if (!$ok) {
            $this->jsonResponse(false, null, t('business.err_api_key'), 404);
        }
        ActivityLogger::info('business.api_key_revoked', 'Отозван API-ключ продавца', 'seller_api_key', (int) $id, [
            'user_id' => $userId,
        ]);
        $this->jsonResponse(true, ['revoked' => true]);
    }

    public function aiInfographic(): void
    {
        $this->aiContract(BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC);
    }

    public function aiSeo(): void
    {
        $this->aiContract(BusinessPackagePolicy::FEATURE_AI_SEO);
    }

    public function aiTryon(): void
    {
        $this->aiContract(BusinessPackagePolicy::FEATURE_AI_TRYON);
    }

    public function analyticsExtended(): void
    {
        $userId = $this->requireUserId();
        $input = $this->getJsonInput();
        $days = isset($_GET['days']) ? (int) $_GET['days'] : (isset($input['days']) ? (int) $input['days'] : null);
        $result = (new SellerAnalyticsService())->extendedSnapshot($userId, $days);
        if (empty($result['ok'])) {
            $this->jsonResponse(false, ['code' => $result['code'] ?? null], $result['error'] ?? t('business.err_generic'), 403);
        }
        $this->jsonResponse(true, $result['data'] ?? []);
    }

    public function managerRequest(): void
    {
        $userId = $this->requireSessionUser();
        Csrf::enforce();
        $input = array_merge($_POST, $this->getJsonInput());
        $message = (string) ($input['message'] ?? '');
        $result = (new BusinessManagerRequestService())->requestContact($userId, $message);
        if (empty($result['ok'])) {
            $code = in_array($result['code'] ?? '', ['need_package', 'features_disabled'], true) ? 403 : 400;
            $this->jsonResponse(false, ['code' => $result['code'] ?? null], $result['error'] ?? t('business.err_generic'), $code);
        }
        $this->jsonResponse(true, [
            'ticket_id' => $result['ticket_id'] ?? null,
            'ticket_number' => $result['ticket_number'] ?? null,
            'manager_assigned' => false,
        ]);
    }

    private function aiContract(string $feature): void
    {
        $userId = $this->requireUserId();
        $input = array_merge($_POST, $this->getJsonInput());
        $operationKey = isset($input['operation_key']) ? (string) $input['operation_key'] : null;
        $result = (new BusinessPackageService())->invokeGeneratorContract($userId, $feature, $operationKey);
        if (!empty($result['ok'])) {
            // Успех возможен только после подключения реального генератора
            $this->jsonResponse(true, [
                'operation_id' => $result['operation_id'] ?? null,
                'operation_key' => $result['operation_key'] ?? null,
                'status' => $result['status'] ?? null,
                'remaining' => $result['remaining'] ?? null,
                'idempotent' => !empty($result['idempotent']),
                'result' => $result['result'] ?? null,
            ]);
        }
        $code = (string) ($result['code'] ?? '');
        $http = match ($code) {
            'feature_not_implemented' => 501,
            'need_package', 'features_disabled' => 403,
            'quota_exceeded', 'operation_in_progress', 'busy' => 409,
            default => 400,
        };
        $this->jsonResponse(false, [
            'code' => $code !== '' ? $code : null,
            'operation_id' => $result['operation_id'] ?? null,
            'operation_key' => $result['operation_key'] ?? null,
            'status' => $result['status'] ?? null,
            'idempotent' => !empty($result['idempotent']),
        ], $result['error'] ?? t('business.err_generic'), $http);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function respondImport(array $result): void
    {
        if (empty($result['ok']) && empty($result['job_id'])) {
            $code = in_array($result['code'] ?? '', ['need_package', 'features_disabled', 'api_not_included'], true) ? 403 : 400;
            $this->jsonResponse(false, ['code' => $result['code'] ?? null], $result['error'] ?? t('business.err_generic'), $code);
        }
        $this->jsonResponse(!empty($result['ok']) || !empty($result['job_id']), [
            'job_id' => $result['job_id'] ?? null,
            'status' => $result['status'] ?? null,
            'report' => $result['report'] ?? null,
            'code' => $result['code'] ?? null,
        ], $result['error'] ?? null, !empty($result['ok']) ? 200 : 422);
    }

    private function requireUserId(): int
    {
        $sessionId = $this->getAuthenticatedUserId();
        if ($sessionId > 0) {
            return $sessionId;
        }

        return $this->requireApiUser();
    }

    private function requireSessionUser(): int
    {
        Auth::requireLogin();

        return (int) Auth::id();
    }

    private function requireApiUser(): int
    {
        $sessionId = $this->getAuthenticatedUserId();
        if ($sessionId > 0) {
            return $sessionId;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || !preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            $this->jsonResponse(false, null, t('business.err_api_auth'), 401);
        }
        $row = (new SellerApiKey())->findActiveByPlainKey($m[1]);
        if (!$row) {
            $this->jsonResponse(false, null, t('business.err_api_auth'), 401);
        }
        (new SellerApiKey())->touchLastUsed((int) $row['id']);

        return (int) $row['user_id'];
    }

    private function readUploadedOrBody(string $field, string $acceptHint): ?string
    {
        unset($acceptHint);
        if (!empty($_FILES[$field]['tmp_name']) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
            $size = (int) ($_FILES[$field]['size'] ?? 0);
            if ($size <= 0 || $size > BusinessPackagePolicy::IMPORT_MAX_BYTES) {
                return null;
            }
            $raw = file_get_contents($_FILES[$field]['tmp_name']);

            return is_string($raw) ? $raw : null;
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (strlen($raw) > BusinessPackagePolicy::IMPORT_MAX_BYTES) {
            return null;
        }

        return $raw;
    }
}
