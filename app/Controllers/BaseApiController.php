<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

abstract class BaseApiController extends Controller
{
    /**
     * @param array<string, mixed>|null $data
     */
    protected function jsonResponse(bool $success, ?array $data = null, ?string $error = null, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode([
            'success' => $success,
            'data' => $data,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function getAuthenticatedUserId(): int
    {
        if (Auth::check()) {
            return (int) Auth::id();
        }

        return 0;
    }

    protected function requireGigsAccess(): void
    {
        if (!Auth::canAccessGigs()) {
            $this->jsonResponse(false, null, t('catalog.not_found'), 404);
        }
    }
}
