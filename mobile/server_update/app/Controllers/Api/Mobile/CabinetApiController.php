<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\AvatarHelper;
use App\Helpers\UploadHelper;
use App\Models\Bonus;
use App\Models\Follow;
use App\Models\Review;
use App\Models\User;
use App\Services\MobileApi\Presenter;

/**
 * Личный кабинет в приложении: фото, «о себе», отзывы обо мне, подписки, рефералы, бонусы.
 * Правила — те же, что у сайта (ProfileController::avatar / updateBio, вкладки reviews/subscriptions/referral).
 */
final class CabinetApiController extends BaseMobileController
{
    private const AVATAR_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_AVATAR = 3 * 1024 * 1024;
    private const MAX_BIO = 500;

    /** POST /me/avatar (multipart: avatar) */
    public function avatar(): void
    {
        $uid = $this->requireUserId();
        $file = $_FILES['avatar'] ?? null;
        if (!is_array($file) || empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->fail('avatar_required', 422, t('flash.avatar_required'));
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > self::MAX_AVATAR) {
            $this->fail('avatar_too_big', 422, t('flash.avatar_too_big'));
        }
        if (!UploadHelper::isAllowedUpload((string) $file['tmp_name'], (string) $file['name'], self::AVATAR_EXT)) {
            $this->fail('avatar_formats', 422, t('flash.avatar_formats'));
        }

        $dir = dirname(__DIR__, 4) . '/public/uploads/avatars';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $users = new User();
        $current = $users->find($uid) ?? [];
        $name = 'avatar_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . UploadHelper::normalizeExt((string) $file['name']);
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            $this->fail('avatar_save_fail', 500, t('flash.avatar_save_fail'));
        }
        if (!empty($current['avatar_file'])) {
            $old = $dir . '/' . basename((string) $current['avatar_file']);
            if (is_file($old)) {
                @unlink($old);
            }
        }
        $users->updateAvatar($uid, $name);
        $this->ok(['user' => Presenter::me($users->find($uid) ?? [])]);
    }

    /** POST /me/bio {bio} */
    public function bio(): void
    {
        $uid = $this->requireUserId();
        $bio = mb_substr(trim((string) ($this->body()['bio'] ?? '')), 0, self::MAX_BIO);
        $users = new User();
        $users->updateBio($uid, $bio);
        $this->ok(['user' => Presenter::me($users->find($uid) ?? [])]);
    }

    /** GET /me/reviews — отзывы обо мне */
    public function reviews(): void
    {
        $uid = $this->requireUserId();
        $model = new Review();
        $stats = $model->statsFor($uid);
        $items = [];
        foreach ($model->forSubject($uid, 100) as $r) {
            $author = ['name' => $r['author_name'] ?? '', 'avatar' => $r['author_avatar'] ?? null, 'avatar_file' => $r['author_avatar_file'] ?? null];
            $title = trim((string) ($r['product_title'] ?? '')) ?: trim((string) ($r['task_title'] ?? ''));
            $items[] = [
                'id' => (int) ($r['id'] ?? 0),
                'rating' => (int) ($r['rating'] ?? 0),
                'comment' => (string) ($r['body'] ?? ''),
                'author_name' => (string) ($r['author_name'] ?? ''),
                'author_avatar_url' => Presenter::media(AvatarHelper::url($author)),
                'product_title' => $title !== '' ? $title : null,
                'created_at' => Presenter::date($r['created_at'] ?? null),
            ];
        }
        $this->ok([
            'rating' => ['average' => (float) ($stats['avg'] ?? 0), 'count' => (int) ($stats['count'] ?? 0)],
            'items' => $items,
        ]);
    }

    /** GET /me/follows — на кого я подписан и кто подписан на меня */
    public function follows(): void
    {
        $uid = $this->requireUserId();
        $model = new Follow();
        $this->ok([
            'following' => array_map(fn (array $u): array => $this->person($u), $model->followingUsers($uid)),
            'followers' => array_map(fn (array $u): array => $this->person($u), $model->followerUsers($uid)),
        ]);
    }

    /** GET /me/referral — ссылка-приглашение и приглашённые */
    public function referral(): void
    {
        $uid = $this->requireUserId();
        $users = new User();
        $user = $users->find($uid) ?? ['id' => $uid];
        $this->ok([
            'code' => $users->referralCodeFor($user),
            'url' => $users->referralUrlFor($user),
            'bonus_per_friend' => Bonus::AMOUNT_REFERRAL,
            'count' => $users->countReferrals($uid),
            'friends' => array_map(fn (array $u): array => $this->person($u), $users->referrals($uid, 100)),
        ]);
    }

    /** GET /me/bonuses — баланс и история бонусов */
    public function bonuses(): void
    {
        $uid = $this->requireUserId();
        $model = new Bonus();
        $this->ok([
            'balance' => $model->balance($uid),
            'rules' => [
                ['type' => Bonus::TYPE_REFERRAL, 'amount' => Bonus::AMOUNT_REFERRAL, 'label' => t('bonuses.tx_referral')],
                ['type' => Bonus::TYPE_LISTING, 'amount' => Bonus::AMOUNT_LISTING, 'label' => t('bonuses.tx_listing')],
                ['type' => Bonus::TYPE_SALE, 'amount' => Bonus::AMOUNT_SALE, 'label' => t('bonuses.tx_sale')],
                ['type' => Bonus::TYPE_FOLLOWER, 'amount' => Bonus::AMOUNT_FOLLOWER, 'label' => t('bonuses.tx_follower')],
            ],
            'transactions' => array_map(static fn (array $t): array => [
                'id' => (int) $t['id'],
                'type' => (string) $t['type'],
                'label' => t('bonuses.tx_' . $t['type']),
                'amount' => (int) $t['amount'],
                'balance_after' => (int) $t['balance_after'],
                'created_at' => Presenter::date($t['created_at'] ?? null),
            ], $model->transactions($uid, 100)),
        ]);
    }

    /** @param array<string, mixed> $u */
    private function person(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'name' => (string) ($u['name'] ?? ''),
            'login' => ($u['login'] ?? '') !== '' ? (string) $u['login'] : null,
            'avatar_url' => Presenter::media(AvatarHelper::url($u)),
            'bio' => ($u['bio'] ?? '') !== '' ? (string) $u['bio'] : null,
            'since' => Presenter::date($u['followed_at'] ?? $u['created_at'] ?? null),
        ];
    }
}
