<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Core\Auth;

/**
 * Проверка прав AI tools: role → permission → ownership.
 */
final class PermissionChecker
{
    public function canUseTool(string $requiredPermission, ?int $userId, string $role = 'guest'): bool
    {
        return match ($requiredPermission) {
            'guest' => true,
            'user' => $userId !== null && $userId > 0,
            'seller', 'owner' => $userId !== null && $userId > 0,
            'admin' => $role === 'admin' || Auth::isAdmin(),
            'manager' => in_array($role, ['manager', 'admin'], true),
            default => false,
        };
    }

    public function ownsOrder(array $order, ?int $userId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        return (int) ($order['buyer_id'] ?? 0) === $userId
            || (int) ($order['seller_id'] ?? 0) === $userId;
    }

    public function ownsProduct(array $product, ?int $userId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        return (int) ($product['user_id'] ?? 0) === $userId;
    }

    public function roleFor(?int $userId): string
    {
        if ($userId === null) {
            return 'guest';
        }
        if (Auth::isAdmin()) {
            return 'admin';
        }
        if (Auth::isManager()) {
            return 'manager';
        }
        return Auth::check() ? 'user' : 'guest';
    }
}
