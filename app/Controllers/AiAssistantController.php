<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Services\CatalogAiAssistant;

class AiAssistantController extends Controller
{
    public function chat(): void
    {
        $raw = file_get_contents('php://input');
        $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $message = trim((string) (($json['message'] ?? null) ?: ($_POST['message'] ?? '')));

        if (mb_strlen($message, 'UTF-8') > 500) {
            $this->json([
                'ok' => false,
                'reply' => 'Слишком длинное сообщение. Сократите до 500 символов.',
                'products' => [],
                'suggestions' => [],
            ], 422);
        }

        try {
            $result = (new CatalogAiAssistant())->reply($message);
            $this->json($result);
        } catch (\Throwable $e) {
            $this->json([
                'ok' => false,
                'reply' => 'Сейчас не удалось обработать запрос. Попробуйте ещё раз или воспользуйтесь поиском в шапке.',
                'products' => [],
                'suggestions' => [],
            ], 500);
        }
    }
}
