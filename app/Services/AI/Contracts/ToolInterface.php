<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /** JSON-schema-like array for LLM / validation */
    public function inputSchema(): array;

    /** Permission key: guest|user|owner|seller|admin */
    public function requiredPermission(): string;

    public function requiresConfirmation(): bool;

    /**
     * @param array<string, mixed> $input
     * @param array{user_id:?int,role:string,request_id:string,conversation_id:?int,context:array} $ctx
     * @return array{ok:bool,data?:mixed,error?:string,pending_confirm?:bool,confirm_token?:string}
     */
    public function execute(array $input, array $ctx): array;
}
