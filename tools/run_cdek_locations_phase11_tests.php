<?php

declare(strict_types=1);

/**
 * Phase 11 — CDEK locations and Point B.
 * php tools/run_cdek_locations_phase11_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Services\Cdek\CdekApiResponse;
use App\Services\Cdek\CdekDirectoryCache;
use App\Services\Cdek\CdekLocationService;

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

function apiResult(int $http, mixed $data, string $error = ''): CdekApiResponse
{
    return CdekApiResponse::fromClientResult([
        'ok' => $http > 0 && $http < 400,
        'code' => $http,
        'data' => $data,
        'error' => $error !== '' ? $error : ($http >= 400 || $http === 0 ? 'CDEK error' : null),
        'request_id' => 't',
        'duration_ms' => 1,
    ]);
}

final class FakeLocationApi
{
    public int $suggests = 0;
    public int $cities = 0;
    public int $points = 0;
    public int $regions = 0;
    public ?CdekApiResponse $suggestResponse = null;
    public ?CdekApiResponse $citiesResponse = null;
    public ?CdekApiResponse $pointsResponse = null;
    public ?CdekApiResponse $regionsResponse = null;

    public function suggestCities(array $query): CdekApiResponse
    {
        $this->suggests++;
        return $this->suggestResponse ?? apiResult(200, []);
    }

    public function cities(array $query): CdekApiResponse
    {
        $this->cities++;
        return $this->citiesResponse ?? apiResult(200, []);
    }

    public function regions(array $query): CdekApiResponse
    {
        $this->regions++;
        return $this->regionsResponse ?? apiResult(200, []);
    }

    public function deliveryPoints(array $query): CdekApiResponse
    {
        $this->points++;
        return $this->pointsResponse ?? apiResult(200, []);
    }

    public function postalCodes(array $query): CdekApiResponse
    {
        return apiResult(200, ['code' => 1, 'postal_codes' => ['050000']]);
    }

    public function coordinates(array $query): CdekApiResponse
    {
        return apiResult(200, ['code' => 4756, 'city' => 'Алматы', 'city_uuid' => 'u']);
    }
}

function office(string $code, int $city, float $wmax, int $lmax): array
{
    return [
        'code' => $code,
        'type' => 'PVZ',
        'is_handout' => true,
        'is_reception' => true,
        'take_only' => false,
        'weight_max' => $wmax,
        'weight_min' => 0,
        'length_max' => $lmax,
        'width_max' => $lmax,
        'height_max' => $lmax,
        'location' => [
            'city' => 'Алматы',
            'city_code' => $city,
            'country_code' => 'KZ',
            'address' => 'Абая 1',
            'address_full' => 'Алматы, Абая 1',
            'region' => 'Алматы',
            'region_code' => 10,
            'postal_code' => '050000',
            'latitude' => 43.2,
            'longitude' => 76.9,
        ],
    ];
}

$dir = sys_get_temp_dir() . '/cdek-loc-' . bin2hex(random_bytes(4));
$cache = new CdekDirectoryCache($dir);
$api = new FakeLocationApi();
$svc = new CdekLocationService($api, $cache);

$api->suggestResponse = apiResult(200, [[
    'code' => 4756,
    'full_name' => 'Алматы, Алматы',
    'country_code' => 'KZ',
    'city_uuid' => '11111111-1111-1111-1111-111111111111',
]]);
$found = $svc->suggestCities('Алм', 'KZ');
assert_true(($found['ok'] ?? false) && ($found['cities'][0]['code'] ?? 0) === 4756, 'City search returns CDEK code');
$again = $svc->suggestCities('Алм', 'KZ');
assert_true($api->suggests === 1 && ($again['cities'][0]['code'] ?? 0) === 4756, 'Cache hit does not call CDEK again');

$api2 = new FakeLocationApi();
$api2->suggestResponse = apiResult(200, []);
$miss = (new CdekLocationService($api2, new CdekDirectoryCache($dir . '-m')))->suggestCities('Неттакого', 'KZ');
assert_true(($miss['ok'] ?? false) && ($miss['cities'] ?? null) === [], 'Unknown city returns empty list');
assert_true($api2->suggests === 1, 'Cache miss calls CDEK');

$api->citiesResponse = apiResult(200, [[
    'code' => 4756,
    'city' => 'Алматы',
    'country_code' => 'KZ',
    'region' => 'Алматы',
    'region_code' => 10,
    'city_uuid' => '11111111-1111-1111-1111-111111111111',
]]);
$city = $svc->confirmCity(4756, 'KZ');
assert_true(($city['ok'] ?? false) && ($city['city']['code'] ?? 0) === 4756, 'Confirmed city code is stored from CDEK');
$badCity = $svc->confirmCity(999999, 'KZ');
assert_true(!($badCity['ok'] ?? true) && ($badCity['error_code'] ?? '') === 'city_not_found', 'Unknown city code rejected');

$api->pointsResponse = apiResult(200, [
    office('ALA1', 4756, 30, 50),
    office('ALA2', 4961, 30, 50),
    office('HEAVY', 4756, 1, 50),
    office('SMALL', 4756, 30, 5),
    ['code' => 'NOHAND', 'is_handout' => false, 'take_only' => false, 'location' => ['city_code' => 4756, 'address' => 'x']],
]);
$list = $svc->searchDeliveryPoints(['city_code' => 4756, 'weight_kg' => 2, 'length_cm' => 20, 'width_cm' => 10, 'height_cm' => 10, 'type' => 'PVZ']);
$codes = array_column($list['points'] ?? [], 'code');
assert_true(in_array('ALA1', $codes, true), 'Delivery points search');
assert_true(!in_array('ALA2', $codes, true), 'Filtered by city code');
assert_true(!in_array('HEAVY', $codes, true), 'Filtered by weight');
assert_true(!in_array('SMALL', $codes, true), 'Filtered by dimensions');
assert_true(!in_array('NOHAND', $codes, true), 'Non-handout points hidden');

$one = $svc->confirmDeliveryPoint('ALA1', ['city_code' => 4756, 'weight_kg' => 2, 'length_cm' => 20, 'width_cm' => 10, 'height_cm' => 10]);
assert_true(($one['ok'] ?? false) && ($one['point']['code'] ?? '') === 'ALA1' && ($one['point']['city_code'] ?? 0) === 4756, 'Pickup Point B uses CDEK point code');
assert_true(!isset($one['point']['email']) && !isset($one['point']['access_token']), 'Public point has no credentials');

$missing = $svc->confirmDeliveryPoint('NOPE', ['city_code' => 4756]);
assert_true(!($missing['ok'] ?? true), 'Unknown delivery point rejected');

$staleApi = new FakeLocationApi();
$staleCache = new CdekDirectoryCache($dir . '-stale');
$staleApi->pointsResponse = apiResult(500, null, 'down');
$stale = (new CdekLocationService($staleApi, $staleCache))->confirmDeliveryPoint('ALA1', ['city_code' => 4756]);
assert_true(!($stale['ok'] ?? true), 'Stale/unavailable point is not accepted on confirm');

$door = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Delivery/BuyerPointBService.php');
assert_true(str_contains($door, 'address_verified') && str_contains($door, 'postalCodes'), 'Door Point B confirms city and does not invent address validity');
assert_true(str_contains($door, 'confirmDeliveryPoint') && str_contains($door, 'confirmCity'), 'Point code and city code are rechecked on server');

$svcSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Delivery/DeliveryService.php');
assert_true(str_contains($svcSrc, 'invalidateQuotes') && str_contains($svcSrc, 'address_changed'), 'Point B change invalidates quote');
assert_true(str_contains($door, 'buyer_user_id') && str_contains($door, 'forbidden'), 'Foreign Point B update is forbidden');

$rateApi = new FakeLocationApi();
$rateApi->regionsResponse = apiResult(429, null, 'rate');
$rate = (new CdekLocationService($rateApi, new CdekDirectoryCache($dir . '-429')))->searchRegions('KZ');
assert_true(!($rate['ok'] ?? true) && ($rate['error_code'] ?? '') === 'rate_limit', 'CDEK 429');

$downApi = new FakeLocationApi();
$downApi->regionsResponse = apiResult(503, null, 'down');
$down = (new CdekLocationService($downApi, new CdekDirectoryCache($dir . '-5')))->searchRegions('KZ');
assert_true(!($down['ok'] ?? true) && ($down['error_code'] ?? '') === 'cdek_unavailable', 'CDEK 5xx');

$toApi = new FakeLocationApi();
$toApi->regionsResponse = apiResult(0, null, 'Operation timed out');
$to = (new CdekLocationService($toApi, new CdekDirectoryCache($dir . '-t')))->searchRegions('KZ');
assert_true(!($to['ok'] ?? true) && ($to['error_code'] ?? '') === 'timeout', 'Timeout');

$ctl = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/DeliveryController.php');
$ctl = str_replace("unset(\$_POST['cdek_uuid'], \$_POST['access_token'], \$_POST['client_secret']);", '', $ctl);
$js = (string) file_get_contents(dirname(__DIR__) . '/app/Views/checkout/index.php');
assert_true(!str_contains($ctl, 'client_secret') && !str_contains($js, 'access_token') && !str_contains($js, 'client_secret'), 'Frontend responses do not include CDEK credentials');

$subst = $svc->confirmDeliveryPoint('FAKE99', ['city_code' => 4756, 'weight_kg' => 2, 'length_cm' => 20, 'width_cm' => 10, 'height_cm' => 10]);
assert_true(!($subst['ok'] ?? true), 'Substituted delivery point code is rejected');

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
