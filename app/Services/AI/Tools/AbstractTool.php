<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\AI\Contracts\ToolInterface;

abstract class AbstractTool implements ToolInterface
{
    public function requiresConfirmation(): bool
    {
        return false;
    }

    protected function ok(mixed $data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    protected function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
