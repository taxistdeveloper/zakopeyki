<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\CatalogImportJob;
use App\Models\Notification;
use App\Models\SellerApiKey;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Business\BusinessManagerRequestService;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\Business\CatalogImportService;
use App\Services\Business\SellerAnalyticsService;
use App\Services\BusinessPackageService;
use App\Services\BusinessUpgradeService;
use App\Services\PersonalLimitService;

class BusinessController extends Controller
{
    public function upgradeForm(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find(Auth::id());
        if ($user) {
            Auth::refresh($user);
        }

        $limit = (new PersonalLimitService())->snapshot($user ?: Auth::user());
        $upgrade = new BusinessUpgradeService();
        $pending = $upgrade->pendingForUser(Auth::id());
        $latest = $upgrade->latestForUser(Auth::id());
        $n = new Notification();

        $this->view('business/upgrade', [
            'title' => t('business.upgrade_title'),
            'currentNav' => 'profile',
            'user' => $user ?: Auth::user(),
            'limit' => $limit,
            'pending' => $pending,
            'latest' => $latest,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function upgradeSubmit(): void
    {
        Auth::requireLogin();
        $result = (new BusinessUpgradeService())->submit(Auth::id(), [
            'entity_type' => (string) ($_POST['entity_type'] ?? ''),
            'business_name' => (string) ($_POST['business_name'] ?? ''),
            'bin' => (string) ($_POST['bin'] ?? ''),
            'phone' => (string) ($_POST['phone'] ?? ''),
            'address' => (string) ($_POST['address'] ?? ''),
        ], $_FILES['docs'] ?? []);

        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('business.err_generic');
            $this->redirect('/business/upgrade');
        }

        $fresh = (new User())->find(Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }

        $_SESSION['flash'] = t('business.flash_upgrade_submitted');
        $this->redirect('/business/upgrade');
    }

    public function packageIndex(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find(Auth::id());
        if ($user) {
            Auth::refresh($user);
        }

        $pkgService = new BusinessPackageService();
        $limit = (new PersonalLimitService())->snapshot($user ?: Auth::user());
        $n = new Notification();
        $uid = Auth::id();
        $subscription = $pkgService->activeSubscription($uid);
        $featuresOn = $pkgService->isFeaturesEnabled();
        $salesOn = $pkgService->isSalesEnabled();

        $catalog = $pkgService->catalog();
        $monthPlans = [];
        foreach ($catalog as $pkg) {
            $slug = (string) ($pkg['slug'] ?? '');
            if ($slug === 'business-month') {
                $monthPlans[] = $pkg;
            }
            // business-3m / 6m / 12m не в продаже (is_active=0 + серверный allowlist)
        }
        // 6/12 мес. заявлены в VAS-14.2, но цена/скидка не утверждены — без кнопки покупки и без суммы
        $pendingLongTerm = [
            ['slug' => 'business-6m', 'name' => t('business.plan_6m_name'), 'duration_days' => 180],
            ['slug' => 'business-12m', 'name' => t('business.plan_12m_name'), 'duration_days' => 365],
        ];

        $addons = [];
        foreach ($pkgService->addons() as $addon) {
            $slug = (string) ($addon['slug'] ?? '');
            if (BusinessPackagePolicy::isAiAddonSlug($slug)) {
                continue;
            }
            $addons[] = $addon;
        }
        $aiAddons = $pkgService->aiAddons();
        $aiAddonSalesFlags = $pkgService->aiAddonSalesFlags();
        $aiQuotas = $subscription ? $pkgService->aiQuotaSnapshot($uid) : [];

        $apiKeys = [];
        $analytics = null;
        $importJob = null;
        if ($subscription && $featuresOn) {
            $apiGate = $pkgService->assertFeature($uid, BusinessPackagePolicy::FEATURE_IMPORT_API);
            if (!empty($apiGate['ok'])) {
                $apiKeys = (new SellerApiKey())->listActiveForUser($uid);
            }
            $analytics = (new SellerAnalyticsService())->extendedSnapshot($uid);
            $jobId = (int) ($_GET['import_job'] ?? 0);
            if ($jobId > 0) {
                $importJob = (new CatalogImportJob())->findForUser($jobId, $uid);
            }
        }

        $this->view('business/package', [
            'title' => t('business.package_title'),
            'currentNav' => 'business_package',
            'user' => $user ?: Auth::user(),
            'limit' => $limit,
            'packages' => $monthPlans,
            'pendingLongTerm' => $pendingLongTerm,
            'addons' => $addons,
            'aiAddons' => $aiAddons,
            'aiAddonSalesFlags' => $aiAddonSalesFlags,
            'aiAddonSalesEnabled' => $pkgService->isAiAddonSalesEnabled(),
            'aiQuotas' => $aiQuotas,
            'subscription' => $subscription,
            'usageRows' => $pkgService->usageSnapshot($uid),
            'payments' => $pkgService->paymentHistory($uid, 10),
            'isBusiness' => $pkgService->isBusinessVerified($user ?: Auth::user()),
            'salesEnabled' => $salesOn,
            'featuresEnabled' => $featuresOn,
            'walletBalance' => (new Wallet())->balance($uid),
            'apiKeys' => $apiKeys,
            'analytics' => $analytics,
            'importJob' => $importJob,
            'idempotencyKey' => 'bp-ui-' . bin2hex(random_bytes(8)),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
            'aiOpStatus' => $_SESSION['ai_op_status'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error'], $_SESSION['ai_op_status']);
    }

    public function packagePurchase(string $id): void
    {
        Auth::requireLogin();
        $idempotency = isset($_POST['idempotency_key']) ? (string) $_POST['idempotency_key'] : null;
        $pkgService = new BusinessPackageService();
        $pkg = $pkgService->findPackage((int) $id);
        $result = $pkgService->purchase(Auth::id(), (int) $id, $idempotency, $_POST);
        $isAi = $pkg && BusinessPackagePolicy::isAiAddonSlug((string) ($pkg['slug'] ?? ''));
        $anchor = $isAi ? '#ai-addons' : '';
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('business.err_generic');
            $this->redirect('/business/package' . $anchor);
        }
        if ($isAi) {
            $_SESSION['flash'] = !empty($result['idempotent'])
                ? t('business.flash_ai_addon_idempotent')
                : t('business.flash_ai_addon_activated');
        } else {
            $_SESSION['flash'] = !empty($result['idempotent'])
                ? t('business.flash_package_idempotent')
                : t('business.flash_package_activated');
        }
        $this->redirect('/business/package' . $anchor);
    }

    public function importXml(): void
    {
        Auth::requireLogin();
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $_SESSION['error'] = t('business.err_import_empty');
            $this->redirect('/business/package#tools-import');
        }
        $raw = file_get_contents($file['tmp_name']);
        if (!is_string($raw) || $raw === '') {
            $_SESSION['error'] = t('business.err_import_empty');
            $this->redirect('/business/package#tools-import');
        }
        $result = (new CatalogImportService())->importXml(Auth::id(), $raw, (string) ($file['name'] ?? 'upload.xml'));
        $this->finishImport($result);
    }

    public function importCsv(): void
    {
        Auth::requireLogin();
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $_SESSION['error'] = t('business.err_import_empty');
            $this->redirect('/business/package#tools-import');
        }
        $raw = file_get_contents($file['tmp_name']);
        if (!is_string($raw) || $raw === '') {
            $_SESSION['error'] = t('business.err_import_empty');
            $this->redirect('/business/package#tools-import');
        }
        $result = (new CatalogImportService())->importCsv(Auth::id(), $raw, (string) ($file['name'] ?? 'upload.csv'));
        $this->finishImport($result);
    }

    public function createApiKey(): void
    {
        Auth::requireLogin();
        $gate = (new BusinessPackageService())->assertFeature(Auth::id(), BusinessPackagePolicy::FEATURE_IMPORT_API);
        if (empty($gate['ok'])) {
            $_SESSION['error'] = $gate['error'] ?? t('business.err_generic');
            $this->redirect('/business/package#tools-api');
        }
        $name = trim((string) ($_POST['name'] ?? 'default'));
        $created = (new SellerApiKey())->createForUser(Auth::id(), $name !== '' ? $name : 'default');
        if (empty($created['ok'])) {
            $_SESSION['error'] = t('business.err_api_key');
            $this->redirect('/business/package#tools-api');
        }
        $_SESSION['flash'] = t('business.api_key_created_once', [
            'key' => (string) ($created['plain_key'] ?? ''),
        ]);
        $this->redirect('/business/package#tools-api');
    }

    public function revokeApiKey(string $id): void
    {
        Auth::requireLogin();
        $ok = (new SellerApiKey())->revoke((int) $id, Auth::id());
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? t('business.api_key_revoked')
            : t('business.err_api_key');
        $this->redirect('/business/package#tools-api');
    }

    public function managerRequest(): void
    {
        Auth::requireLogin();
        $result = (new BusinessManagerRequestService())->requestContact(
            Auth::id(),
            (string) ($_POST['message'] ?? '')
        );
        if (empty($result['ok'])) {
            $_SESSION['error'] = $result['error'] ?? t('business.err_generic');
            $this->redirect('/business/package#tools-manager');
        }
        $_SESSION['flash'] = t('business.manager_request_ok', [
            'number' => (string) ($result['ticket_number'] ?? ''),
        ]);
        $this->redirect('/business/package#tools-manager');
    }

    public function aiInfographic(): void
    {
        $this->runAiTool(BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC);
    }

    public function aiTryon(): void
    {
        $this->runAiTool(BusinessPackagePolicy::FEATURE_AI_TRYON);
    }

    private function runAiTool(string $feature): void
    {
        Auth::requireLogin();
        $operationKey = isset($_POST['operation_key']) ? (string) $_POST['operation_key'] : null;
        $result = (new BusinessPackageService())->invokeGeneratorContract(Auth::id(), $feature, $operationKey);
        if (!empty($result['ok'])) {
            $_SESSION['flash'] = t('business.ai_op_success');
            $this->redirect('/business/package#tools-ai');
        }
        // Не показываем успех при 501 / ошибке — только серверное сообщение
        $_SESSION['error'] = $result['error'] ?? t('business.err_generic');
        if (!empty($result['status'])) {
            $_SESSION['ai_op_status'] = (string) $result['status'];
        }
        $this->redirect('/business/package#tools-ai');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finishImport(array $result): void
    {
        $jobId = (int) ($result['job_id'] ?? 0);
        if (!empty($result['ok'])) {
            $_SESSION['flash'] = t('business.import_ok', [
                'status' => (string) ($result['status'] ?? ''),
                'ok' => (string) (int) (($result['report']['ok'] ?? 0)),
                'errors' => (string) (int) (($result['report']['error_total'] ?? 0)),
            ]);
        } else {
            $_SESSION['error'] = $result['error'] ?? t('business.err_import_failed');
        }
        $hash = '#tools-import';
        if ($jobId > 0) {
            $this->redirect('/business/package?import_job=' . $jobId . $hash);
        }
        $this->redirect('/business/package' . $hash);
    }
}
