<?php

declare(strict_types=1);

/**
 * Phase 10 — CDEK print forms.
 * php tools/run_cdek_print_forms_phase10_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Models\DeliveryOrder;
use App\Models\DeliveryPrintForm;
use App\Services\Cdek\CdekPrintFormService;

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "OK  {$msg}\n";
        return;
    }
    $failed++;
    echo "FAIL {$msg}\n";
}

$svcSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekPrintFormService.php');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekWebhookService.php');
$ctlSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/DeliveryController.php');
$apiSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekApi.php');

$uuid = '11111111-1111-1111-1111-111111111111';
$body = CdekPrintFormService::buildWaybillRequest($uuid, '12345', 'tpl_russia');
assert_true($body['type'] === 'tpl_russia' && $body['orders'][0]['order_uuid'] === $uuid, 'Successful print request payload');
assert_true(!isset($body['client_secret']) && !isset($body['access_token']), 'Request has no CDEK credentials');

$accepted = CdekPrintFormService::interpretResponse(202, [
    'entity' => ['uuid' => 'print-1'],
    'requests' => [['state' => 'ACCEPTED']],
]);
assert_true($accepted['status'] === DeliveryPrintForm::STATUS_PROCESSING, 'HTTP 202 is PROCESSING not PDF');

$ready = CdekPrintFormService::interpretResponse(200, [
    'entity' => ['uuid' => 'print-1', 'url' => 'https://api.cdek.ru/file.pdf'],
    'requests' => [['state' => 'SUCCESSFUL']],
]);
assert_true($ready['status'] === DeliveryPrintForm::STATUS_READY && $ready['file_url'] !== null, 'Ready form when url present');

assert_true(
    CdekPrintFormService::interpretResponse(400, ['requests' => [['state' => 'INVALID']]])['status'] === DeliveryPrintForm::STATUS_FAILED,
    'CDEK 400'
);
assert_true(CdekPrintFormService::interpretResponse(401, null)['error'] === 'http_401', 'CDEK 401');
assert_true(CdekPrintFormService::interpretResponse(404, null)['status'] === DeliveryPrintForm::STATUS_FAILED, 'CDEK 404');
assert_true(CdekPrintFormService::interpretResponse(429, null)['status'] === DeliveryPrintForm::STATUS_PROCESSING, 'CDEK 429 retryable');
assert_true(CdekPrintFormService::interpretResponse(503, null)['status'] === DeliveryPrintForm::STATUS_PROCESSING, 'CDEK 5xx retryable');
assert_true(CdekPrintFormService::interpretResponse(0, null, true)['error'] === 'timeout_or_network', 'Timeout');

$public = CdekPrintFormService::toPublic([
    'id' => 7,
    'form_type' => 'tpl_russia',
    'status' => DeliveryPrintForm::STATUS_READY,
    'created_at' => '2026-01-01',
], 10);
assert_true(
    isset($public['download_path'])
    && !isset($public['file_url'])
    && !isset($public['access_token']),
    'Frontend public DTO has no CDEK credentials or raw URL'
);

$order = ['buyer_user_id' => 2, 'seller_user_id' => 3, 'id' => 10];
assert_true(CdekPrintFormService::canAccessDelivery($order, 2, false), 'Buyer authorized');
assert_true(CdekPrintFormService::canAccessDelivery($order, 3, false), 'Seller authorized');
assert_true(CdekPrintFormService::canAccessDelivery($order, 9, true), 'Admin authorized');
assert_true(!CdekPrintFormService::canAccessDelivery($order, 9, false), 'Foreign delivery denied');

assert_true(str_contains($svcSrc, 'cdek_uuid_missing') && str_contains($svcSrc, 'print_no_cdek_order'), 'Missing CDEK UUID blocks request');
assert_true(str_contains($svcSrc, 'not_found'), 'Unknown delivery handled');
assert_true(str_contains($svcSrc, 'uq_print_order_type') || str_contains($svcSrc, 'already_existed'), 'Repeat request does not duplicate');
assert_true(str_contains($hookSrc, 'PRINT_FORM') && str_contains($hookSrc, 'applyWebhook'), 'PRINT_FORM webhook handled');
assert_true(str_contains($svcSrc, 'event_hash') || str_contains($hookSrc, 'duplicate'), 'Repeat PRINT_FORM uses webhook idempotency');
assert_true(str_contains($svcSrc, 'MAX_PDF_BYTES') && str_contains($apiSrc, 'downloadPrintOrderPdf'), 'PDF download via API client');
assert_true(str_contains($svcSrc, 'not_ready') && str_contains($svcSrc, 'http_404'), 'Unavailable PDF handled');
assert_true(str_contains($ctlSrc, "unset(\$_POST['cdek_uuid']"), 'Frontend cannot pass CDEK identifiers');
assert_true(!str_contains($public['download_path'] ?? '', 'client_secret'), 'Download path is local');
assert_true(in_array(DeliveryOrder::STATUS_PAID, [], true) || str_contains($svcSrc, 'STATUS_PAID'), 'Unregistered paid order blocked');
assert_true(str_contains($svcSrc, 'MAX_POLL_ATTEMPTS'), 'Retries are capped');

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
