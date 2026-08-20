<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\BusinessUpgradeRequest;
use App\Models\Notification;
use App\Models\User;

class BusinessUpgradeService
{
    private const DOC_EXT = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    private const MAX_DOC = 8 * 1024 * 1024;
    private const MAX_FILES = 5;

    private User $users;
    private BusinessUpgradeRequest $requests;

    public function __construct(?User $users = null, ?BusinessUpgradeRequest $requests = null)
    {
        $this->users = $users ?? new User();
        $this->requests = $requests ?? new BusinessUpgradeRequest();
        $this->users->ensureBusinessSchema();
    }

    public function pendingForUser(int $userId): ?array
    {
        return $this->requests->latestPending($userId);
    }

    public function latestForUser(int $userId): ?array
    {
        return $this->requests->latestForUser($userId);
    }

    /**
     * @param array{entity_type: string, business_name: string, bin: string, phone?: string, address?: string} $data
     * @return array{ok: bool, error?: string, request_id?: int}
     */
    public function submit(int $userId, array $data, array $files): array
    {
        $user = $this->users->find($userId);
        if (!$user) {
            return ['ok' => false, 'error' => t('business.err_user')];
        }

        if (($user['account_type'] ?? '') === 'business' && ($user['business_status'] ?? '') === 'verified') {
            return ['ok' => false, 'error' => t('business.err_already_business')];
        }

        if ($this->requests->latestPending($userId)) {
            return ['ok' => false, 'error' => t('business.err_pending_exists')];
        }

        $entity = strtolower(trim((string) ($data['entity_type'] ?? '')));
        if (!in_array($entity, ['ip', 'too'], true)) {
            return ['ok' => false, 'error' => t('business.err_entity')];
        }

        $name = trim((string) ($data['business_name'] ?? ''));
        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'error' => t('business.err_name')];
        }

        $bin = preg_replace('/\D/', '', (string) ($data['bin'] ?? '')) ?? '';
        if (strlen($bin) !== 12) {
            return ['ok' => false, 'error' => t('business.err_bin')];
        }

        $docs = $this->storeDocs($userId, $files);
        if (!empty($docs['error'])) {
            return ['ok' => false, 'error' => $docs['error']];
        }
        if (empty($docs['files'])) {
            return ['ok' => false, 'error' => t('business.err_docs')];
        }

        $id = $this->requests->createRequest([
            'user_id' => $userId,
            'entity_type' => $entity,
            'business_name' => mb_substr($name, 0, 255),
            'bin' => $bin,
            'phone' => mb_substr(trim((string) ($data['phone'] ?? '')), 0, 32),
            'address' => mb_substr(trim((string) ($data['address'] ?? '')), 0, 500),
            'doc_files' => $docs['files'],
        ]);

        $this->users->setBusinessStatus($userId, 'pending', [
            'business_entity_type' => $entity,
            'business_name' => mb_substr($name, 0, 255),
            'bin' => $bin,
        ]);

        (new Notification())->createFor($userId, t('business.notify_upgrade_submitted'));

        ActivityLogger::info('business.upgrade_submit', 'Заявка на бизнес-аккаунт', 'business_upgrade', $id, [
            'entity' => $entity,
            'bin' => $bin,
        ]);

        return ['ok' => true, 'request_id' => $id];
    }

    /** @return array{ok: bool, error?: string} */
    public function approve(int $requestId, int $adminId, ?string $note = null): array
    {
        $req = $this->requests->find($requestId);
        if (!$req || ($req['status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => t('business.err_request')];
        }

        $userId = (int) $req['user_id'];
        $this->requests->markReviewed($requestId, 'approved', $adminId, $note);
        $this->users->promoteToBusiness($userId, [
            'business_entity_type' => $req['entity_type'],
            'business_name' => $req['business_name'],
            'bin' => $req['bin'],
        ]);

        (new Notification())->createFor($userId, t('business.notify_upgrade_approved'));
        ActivityLogger::info('business.upgrade_approve', 'Бизнес-аккаунт одобрен', 'business_upgrade', $requestId, [
            'user_id' => $userId,
        ]);

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function reject(int $requestId, int $adminId, string $reason): array
    {
        $req = $this->requests->find($requestId);
        if (!$req || ($req['status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => t('business.err_request')];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => t('business.err_reject_reason')];
        }

        $userId = (int) $req['user_id'];
        $this->requests->markReviewed($requestId, 'rejected', $adminId, $reason);
        $this->users->setBusinessStatus($userId, 'rejected', [
            'business_rejected_reason' => mb_substr($reason, 0, 500),
        ]);

        (new Notification())->createFor($userId, t('business.notify_upgrade_rejected', ['reason' => $reason]));
        ActivityLogger::info('business.upgrade_reject', 'Бизнес-аккаунт отклонён', 'business_upgrade', $requestId, [
            'user_id' => $userId,
        ]);

        return ['ok' => true];
    }

    /** @return array{files?: list<string>, error?: string} */
    private function storeDocs(int $userId, array $files): array
    {
        if (empty($files['name']) || !is_array($files['name'])) {
            return ['files' => []];
        }

        $dir = __DIR__ . '/../../public/uploads/business';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['error' => t('business.err_upload')];
        }

        $saved = [];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if (count($saved) >= self::MAX_FILES) {
                break;
            }
            $name = (string) ($files['name'][$i] ?? '');
            if ($name === '' || (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                return ['error' => t('business.err_upload')];
            }
            if ((int) ($files['size'][$i] ?? 0) > self::MAX_DOC) {
                return ['error' => t('business.err_doc_size')];
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::DOC_EXT, true)) {
                return ['error' => t('business.err_doc_type')];
            }
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return ['error' => t('business.err_upload')];
            }
            $filename = 'biz_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $filename)) {
                return ['error' => t('business.err_upload')];
            }
            $saved[] = $filename;
        }

        return ['files' => $saved];
    }
}
