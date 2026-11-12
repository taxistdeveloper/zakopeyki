<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TryOnAsset;
use App\Services\AI\Core\RateLimiter;
use App\Services\TryOn\TryOnException;
use App\Services\TryOn\TryOnService;

/**
 * JSON API виртуальной примерки: /api/v1/try-on/* (docs/tryon/02-api-contracts.md).
 * CSRF для POST проверяет Router (сессия). Bearer для мобильного — PHASE 5.
 */
class TryOnController extends BaseApiController
{
    private ?TryOnService $service = null;

    public function capabilities(): void
    {
        $this->guard(function (): void {
            $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : null;
            $userId = $this->getAuthenticatedUserId();
            $this->jsonResponse(true, $this->service()->capabilities($userId > 0 ? $userId : null, $productId));
        });
    }

    public function create(): void
    {
        $this->guard(function (): void {
            $userId = $this->requireUser();
            $this->rateLimit($userId);

            $file = $_FILES['photo'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                $err = is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
                throw new TryOnException(
                    in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'file_too_large' : 'photo_required',
                    in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422
                );
            }

            $result = $this->service()->create(
                $userId,
                (int) ($_POST['product_id'] ?? 0),
                max(0, (int) ($_POST['garment_image_index'] ?? 0)),
                (string) ($_POST['consent'] ?? '') === '1',
                (string) ($_POST['consent_version'] ?? ''),
                (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? ''),
                (string) $file['tmp_name'],
                (int) ($file['size'] ?? 0),
            );

            $this->jsonResponse(true, [
                'job' => TryOnService::present($result['job']),
                'idempotent' => $result['idempotent'],
                'reused' => $result['reused'],
            ], null, $result['idempotent'] ? 200 : 202);
        });
    }

    public function index(): void
    {
        $this->guard(function (): void {
            $userId = $this->requireUser();
            $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : null;
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $jobs = array_map([TryOnService::class, 'present'], $this->service()->listOwned($userId, $productId, $limit));
            $this->jsonResponse(true, ['jobs' => $jobs]);
        });
    }

    public function show(string $id): void
    {
        $this->guard(function () use ($id): void {
            $userId = $this->requireUser();
            $this->jsonResponse(true, ['job' => TryOnService::present($this->service()->getOwned($userId, $id))]);
        });
    }

    public function result(string $id): void
    {
        $this->sendImage($id, TryOnAsset::KIND_RESULT);
    }

    public function photo(string $id): void
    {
        $this->sendImage($id, TryOnAsset::KIND_PERSON);
    }

    public function delete(string $id): void
    {
        $this->guard(function () use ($id): void {
            $userId = $this->requireUser();
            $this->rateLimit($userId);
            $this->service()->delete($userId, $id);
            $this->jsonResponse(true, ['deleted' => true]);
        });
    }

    private function sendImage(string $id, string $kind): void
    {
        $this->guard(function () use ($id, $kind): void {
            $userId = $this->requireUser();
            $bytes = $this->service()->imageFor($userId, $id, $kind);
            http_response_code(200);
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: private, no-store, max-age=0');
            header('Content-Disposition: inline; filename="tryon.jpg"');
            header('X-Content-Type-Options: nosniff');
            echo $bytes;
            exit;
        });
    }

    private function requireUser(): int
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId <= 0) {
            throw new TryOnException('auth_required', 401);
        }

        return $userId;
    }

    private function rateLimit(int $userId): void
    {
        $r = (new RateLimiter(['per_minute' => 6, 'per_hour' => 30]))->attempt('tryon:u' . $userId);
        if (empty($r['allowed'])) {
            throw new TryOnException('rate_limited', 429, ['retry_after' => (int) ($r['retry_after'] ?? 60)]);
        }
    }

    private function service(): TryOnService
    {
        return $this->service ??= new TryOnService();
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (TryOnException $e) {
            if (isset($e->extra['retry_after'])) {
                header('Retry-After: ' . max(1, (int) $e->extra['retry_after']));
            }
            $data = ['code' => $e->errorCode] + array_diff_key($e->extra, ['reason' => true]);
            $this->jsonResponse(false, $data, self::message($e->errorCode), $e->httpStatus);
        } catch (\Throwable $e) {
            error_log('[tryon] ' . get_class($e) . ': ' . $e->getMessage());
            $this->jsonResponse(false, ['code' => 'internal_error'], self::message('internal_error'), 500);
        }
    }

    private static function message(string $code): string
    {
        $key = 'tryon.err.' . $code;
        $text = t($key);

        return $text === $key ? t('tryon.err.generic') : $text;
    }
}
