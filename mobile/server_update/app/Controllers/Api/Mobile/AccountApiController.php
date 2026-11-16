<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ActivityLogger;
use App\Helpers\Totp;
use App\Models\Notification;
use App\Models\User;
use App\Services\MobileApi\Presenter;
use App\Services\Security\AuthThrottle;

/**
 * Настройки аккаунта в приложении: личные данные, пароль, 2FA, уведомления.
 * Правила те же, что у сайта (ProfileController::updatePersonal / updatePassword / twoFactor*).
 */
final class AccountApiController extends BaseMobileController
{
    // ---------------------------------------------------------------- личные данные

    /** GET /me/profile */
    public function profile(): void
    {
        $uid = $this->requireUserId();
        $this->ok(['profile' => $this->profilePayload($uid)]);
    }

    /** POST /me/profile {first_name, last_name, login, phone} */
    public function updateProfile(): void
    {
        $uid = $this->requireUserId();
        $users = new User();
        $first = mb_substr($this->input('first_name'), 0, 60);
        $last = mb_substr($this->input('last_name'), 0, 60);
        $login = mb_substr($this->input('login'), 0, 40);
        $phone = mb_substr($this->input('phone'), 0, 32);

        if ($first === '') {
            $this->fail('validation_failed', 422, t('flash.name_login_required'), ['first_name' => t('flash.name_login_required')]);
        }
        if ($login === '') {
            $this->fail('validation_failed', 422, t('flash.name_login_required'), ['login' => t('flash.name_login_required')]);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
            $this->fail('validation_failed', 422, t('flash.login_format'), ['login' => t('flash.login_format')]);
        }
        $other = $users->findByLogin($login);
        if ($other && (int) $other['id'] !== $uid) {
            $this->fail('login_taken', 409, t('flash.login_taken'), ['login' => t('flash.login_taken')]);
        }

        $users->updateProfile($uid, ['first_name' => $first, 'last_name' => $last, 'login' => $login, 'phone' => $phone]);
        $user = $users->find($uid) ?? [];
        $this->ok(['profile' => $this->profilePayload($uid), 'user' => Presenter::me($user)]);
    }

    // ---------------------------------------------------------------- пароль

    /** POST /me/password {current_password?, password} */
    public function changePassword(): void
    {
        $uid = $this->requireUserId();
        $this->throttle('pwd:' . $uid);
        $users = new User();
        $pass = (string) ($this->body()['password'] ?? '');
        $current = (string) ($this->body()['current_password'] ?? '');

        if (strlen($pass) < 8) {
            $this->fail('weak_password', 422, t('flash.password_min'), ['password' => t('flash.password_min')]);
        }
        $user = $users->find($uid) ?? [];
        if (!empty($user['password']) && ($current === '' || !$users->verifyPassword($uid, $current))) {
            $this->fail('wrong_password', 403, t('flash.wrong_password'), ['current_password' => t('flash.wrong_password')]);
        }
        $users->updatePassword($uid, $pass);
        ActivityLogger::info('auth.password_changed', 'Смена пароля (mobile)', 'user', $uid);
        $this->ok(['changed' => true, 'message' => t('flash.password_changed')]);
    }

    // ---------------------------------------------------------------- 2FA

    /**
     * POST /me/2fa/setup → {secret, otpauth_uri}
     * Секрет хранится только у клиента до подтверждения; включение — после верного кода из приложения-аутентификатора.
     */
    public function twoFactorSetup(): void
    {
        $uid = $this->requireUserId();
        $users = new User();
        $user = $users->find($uid) ?? [];
        if ($users->hasTwoFactor($user)) {
            $this->fail('two_factor_already', 409, t('flash.two_factor_already'));
        }
        $secret = Totp::generateSecret();
        $this->ok([
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, (string) ($user['email'] ?? ''), 'Zakopeyki.kz'),
        ]);
    }

    /** POST /me/2fa/confirm {secret, code} → {recovery_codes} */
    public function twoFactorConfirm(): void
    {
        $uid = $this->requireUserId();
        $this->throttle('2fa:' . $uid);
        $users = new User();
        $user = $users->find($uid) ?? [];
        if ($users->hasTwoFactor($user)) {
            $this->fail('two_factor_already', 409, t('flash.two_factor_already'));
        }
        $secret = strtoupper($this->input('secret'));
        if (!preg_match('/^[A-Z2-7]{16,64}$/', $secret)) {
            $this->fail('two_factor_setup_expired', 422, t('flash.two_factor_setup_expired'));
        }
        if (!Totp::verify($secret, $this->input('code'))) {
            $this->fail('two_factor_invalid', 422, t('flash.two_factor_invalid'), ['code' => t('flash.two_factor_invalid')]);
        }
        $recovery = Totp::generateRecoveryCodes();
        $users->enableTwoFactor($uid, $secret, $recovery);
        ActivityLogger::info('auth.2fa_enabled', '2FA включена (mobile)', 'user', $uid);
        $this->ok(['enabled' => true, 'recovery_codes' => $recovery]);
    }

    /** POST /me/2fa/disable {password?, code} */
    public function twoFactorDisable(): void
    {
        $uid = $this->requireUserId();
        $this->throttle('2fa:' . $uid);
        $users = new User();
        $user = $users->find($uid) ?? [];
        if (!$users->hasTwoFactor($user)) {
            $this->ok(['enabled' => false]);
        }
        $password = (string) ($this->body()['password'] ?? '');
        if (!empty($user['password']) && ($password === '' || !$users->verifyPassword($uid, $password))) {
            $this->fail('wrong_password', 403, t('flash.wrong_password'), ['password' => t('flash.wrong_password')]);
        }
        $code = $this->input('code');
        $ok = Totp::verify((string) $user['two_factor_secret'], $code) || $users->consumeRecoveryCode($uid, $code);
        if (!$ok) {
            $this->fail('two_factor_invalid', 422, t('flash.two_factor_invalid'), ['code' => t('flash.two_factor_invalid')]);
        }
        $users->disableTwoFactor($uid);
        ActivityLogger::info('auth.2fa_disabled', '2FA выключена (mobile)', 'user', $uid);
        $this->ok(['enabled' => false]);
    }

    // ---------------------------------------------------------------- уведомления

    /** GET /me/notifications */
    public function notifications(): void
    {
        $uid = $this->requireUserId();
        $n = new Notification();
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'message' => (string) ($r['message'] ?? ''),
            'link' => ($r['link'] ?? '') !== '' ? (string) $r['link'] : null,
            'is_read' => !empty($r['is_read']),
            'created_at' => Presenter::date($r['created_at'] ?? null),
        ], $n->forUser($uid, 100));
        $this->ok(['items' => $items, 'unread' => $n->unreadCount($uid)]);
    }

    /** POST /me/notifications/read — отметить все прочитанными. */
    public function notificationsRead(): void
    {
        $uid = $this->requireUserId();
        (new Notification())->markAllRead($uid);
        $this->ok(['unread' => 0]);
    }

    /** POST /me/notifications/clear */
    public function notificationsClear(): void
    {
        $uid = $this->requireUserId();
        (new Notification())->clearAll($uid);
        $this->ok(['items' => [], 'unread' => 0]);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function profilePayload(int $uid): array
    {
        $users = new User();
        $u = $users->find($uid) ?? [];
        $login = (string) ($u['login'] ?? '');

        return [
            'first_name' => (string) ($u['first_name'] ?? ''),
            'last_name' => (string) ($u['last_name'] ?? ''),
            'login' => $login,
            'phone' => (string) ($u['phone'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'email_verified' => $users->isEmailVerified($u),
            'has_password' => !empty($u['password']),
            'two_factor_enabled' => $users->hasTwoFactor($u),
        ];
    }

    private function throttle(string $key): void
    {
        $res = (new AuthThrottle())->hitAll([['two_factor', $key], ['login_ip', AuthThrottle::ip()]]);
        if (empty($res['allowed'])) {
            $this->fail('too_many_attempts', 429, null, [], ['Retry-After' => (string) ((int) ($res['retry_after'] ?? 60))]);
        }
    }
}
