<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Core\Database;
use App\Services\AI\Contracts\ToolInterface;
use PDO;

final class ToolManager
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function __construct(
        private readonly PermissionChecker $permissions = new PermissionChecker(),
        private readonly ?PDO $pdo = null,
    ) {
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** @param list<ToolInterface> $tools */
    public function registerMany(array $tools): void
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    /** @return list<ToolInterface> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     * @param array{user_id:?int,role:string,request_id:string,conversation_id:?int,context:array} $ctx
     */
    public function execute(string $name, array $input, array $ctx): array
    {
        $tool = $this->get($name);
        if ($tool === null) {
            return ['ok' => false, 'error' => "Tool «{$name}» не найден"];
        }

        if (!$this->permissions->canUseTool($tool->requiredPermission(), $ctx['user_id'] ?? null, $ctx['role'] ?? 'guest')) {
            $this->audit($ctx, $name, $input, null, 'denied', 'Недостаточно прав');
            return ['ok' => false, 'error' => 'Недостаточно прав для этого действия.', 'status' => 'denied'];
        }

        $started = hrtime(true);
        try {
            $result = $tool->execute($input, $ctx);
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            $status = !empty($result['pending_confirm']) ? 'pending_confirm'
                : (!empty($result['ok']) ? 'ok' : 'error');
            $this->audit($ctx, $name, $input, $result, $status, $result['error'] ?? null, $latency);
            return $result;
        } catch (\Throwable $e) {
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->audit($ctx, $name, $input, null, 'error', $e->getMessage(), $latency);
            return ['ok' => false, 'error' => 'Сейчас не удалось выполнить действие. Попробуйте ещё раз.'];
        }
    }

    private function audit(
        array $ctx,
        string $toolName,
        array $input,
        ?array $output,
        string $status,
        ?string $error = null,
        ?int $latencyMs = null
    ): void {
        try {
            $pdo = $this->pdo ?? Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO ai_tool_calls
                 (request_id, conversation_id, user_id, tool_name, input_json, output_json, status, error_message, latency_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (string) ($ctx['request_id'] ?? ''),
                $ctx['conversation_id'] ?? null,
                $ctx['user_id'] ?? null,
                $toolName,
                json_encode($input, JSON_UNESCAPED_UNICODE),
                $output !== null ? json_encode($output, JSON_UNESCAPED_UNICODE) : null,
                $status,
                $error !== null ? mb_substr($error, 0, 500) : null,
                $latencyMs,
            ]);
        } catch (\Throwable) {
            // таблица может ещё не существовать до миграции
        }
    }
}
