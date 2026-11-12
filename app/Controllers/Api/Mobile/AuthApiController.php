<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ActivityLogger;
use App\Helpers\Totp;
use App\Models\User;
use App\Services\Auth\AccountDeletionService;
use App\Services\Auth\AccountService;
use App\Services\Auth\IdTokenVerifier;
use App\Services\MobileApi\Presenter;
use App\Services\MobileApi\TokenService;
use App\Services\Security\AuthThrottle;

/**
 * /api/mobile/v1/auth/* — вход по email+паролю, 2FA, refresh, выход.
 * Бизнес-правила входа совпадают с web (AuthController::login / twoFactorVerify).
 */
final class AuthApiController extends BaseMobileController
{
    public function login(): void
    {
        $email = $this->input('email');
        $password = (string) ($this->body()['password'] ?? '');
        $device = $this->device();
        if ($device['device_id'] === '') {
            $this->fail('device_id_required', 422, null, ['device_id' => self::message('device_id_required')]);
        }
        if ($email === '' || $password === '') {
            $this->fail('validation_failed', 422, null, array_filter([
                'email' => $email === '' ? self::message('field_required') : null,
                'password' => $password === '' ? self::message('field_required') : null,
            ]));
        }

        $this->throttle([['login_ip', AuthThrottle::ip()], ['login_email', $email]]);

        $users = new User();
        $user = $users->findByEmail($email);
        if (!$user || empty($user['password']) || !password_verify($password, (string) $user['password'])) {
            ActivityLogger::warning('auth.login_failed', 'Неудачная попытка входа (mobile)', null, null, ['channel' => 'mobile']);
            $this->fail('bad_credentials', 401);
        }
        if ($users->isBlocked($user)) {
            $this->fail('account_blocked', 403);
        }

        $tokens = new TokenService();
        if ($users->hasTwoFactor($user)) {
            $this->ok([
                'two_factor_required' => true,
                'challenge_token' => $tokens->issueChallenge((int) $user['id'], $device['device_id']),
                'expires_in' => TokenService::CHALLENGE_TTL,
            ]);
        }

        ActivityLogger::info('auth.login', 'Вход в аккаунт (mobile)', 'user', (int) $user['id'], ['platform' => $device['platform']]);
        $this->ok($this->session($tokens->issue((int) $user['id'], $device), $user));
    }

    /** POST /auth/register — регистрация по тем же правилам, что на сайте (AccountService). */
    public function register(): void
    {
        $device = $this->requireDevice();
        $this->throttle([['register_ip', AuthThrottle::ip()]]);

        $accept = $this->body()['accept_offer'] ?? false;
        $result = (new AccountService())->register([
            'name' => $this->input('name'),
            'email' => $this->input('email'),
            'password' => (string) ($this->body()['password'] ?? ''),
            'phone' => $this->input('phone'),
            'accept_offer' => $accept === true || $accept === 1 || $accept === '1' || $accept === 'true',
        ], $this->input('referral_code'));

        if (!$result['ok']) {
            $status = $result['error'] === 'email_taken' ? 409 : 422;
            $field = (string) ($result['field'] ?? '');
            $this->fail($result['error'], $status, null, $field !== '' ? [$field => self::message($result['error'])] : []);
        }

        $user = $result['user'];
        ActivityLogger::info('auth.login', 'Вход после регистрации (mobile)', 'user', (int) $user['id'], ['platform' => $device['platform']]);
        $this->ok($this->session((new TokenService())->issue((int) $user['id'], $device), $user) + [
            'registration_bonus_points' => $result['bonus'] > 0 ? $result['bonus'] : null,
        ], [], 201);
    }

    /** POST /auth/forgot-password — ответ одинаковый, существует email или нет. */
    public function forgotPassword(): void
    {
        $email = $this->input('email');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('invalid_email', 422, null, ['email' => self::message('invalid_email')]);
        }
        $this->throttle([['forgot_ip', AuthThrottle::ip()], ['forgot_email', $email]]);
        (new AccountService())->requestPasswordReset($email);
        $this->ok(['sent' => true, 'message' => self::message('reset_sent')]);
    }

    /** GET /me/deletion — можно ли удалить аккаунт и что для этого нужно. */
    public function deletionStatus(): void
    {
        $uid = $this->requireUserId();
        $svc = new AccountDeletionService();
        $blockers = $this->presentBlockers($svc->blockers($uid));
        $this->ok([
            'can_delete' => $blockers === [],
            'blockers' => $blockers,
            'requires_password' => $svc->hasPassword($uid),
            'reauth_window_seconds' => AccountDeletionService::REAUTH_WINDOW,
        ]);
    }

    /**
     * POST /me/delete {confirm: true, password?}
     * Аккаунт с паролем — пароль; без пароля (Sign in with Apple) — вход на этом устройстве
     * не старше 10 минут, иначе 403 reauth_required (клиент просит войти заново).
     */
    public function deleteAccount(): void
    {
        $uid = $this->requireUserId();
        $confirm = $this->body()['confirm'] ?? false;
        if ($confirm !== true && $confirm !== 'true' && $confirm !== 1 && $confirm !== '1') {
            $this->fail('validation_failed', 422, null, ['confirm' => self::message('field_required')]);
        }
        $svc = new AccountDeletionService();
        if ($svc->hasPassword($uid)) {
            $this->throttle([['login_email', 'delete:' . $uid]]);
            $password = (string) ($this->body()['password'] ?? '');
            if ($password === '' || !(new User())->verifyPassword($uid, $password)) {
                $this->fail('wrong_password', 403, null, ['password' => self::message('wrong_password')]);
            }
        } else {
            $age = (new TokenService())->familyAgeSeconds((string) $this->currentFamilyId());
            if ($age === null || $age > AccountDeletionService::REAUTH_WINDOW) {
                $this->fail('reauth_required', 403);
            }
        }

        $result = $svc->delete($uid, 'mobile');
        if (!$result['ok']) {
            if ($result['error'] === 'deletion_blocked') {
                self::send([
                    'success' => false,
                    'error' => ['code' => 'deletion_blocked', 'message' => self::message('deletion_blocked')],
                    'data' => ['blockers' => $this->presentBlockers($result['blockers'] ?? [])],
                ], 409);
            }
            $this->fail($result['error'] === 'not_found' ? 'not_found' : 'delete_failed', $result['error'] === 'not_found' ? 404 : 500);
        }
        $this->ok(['deleted' => true]);
    }

    /**
     * @param list<array<string, mixed>> $blockers
     * @return list<array<string, mixed>>
     */
    private function presentBlockers(array $blockers): array
    {
        return array_map(static fn (array $b): array => $b + [
            'message' => \App\Core\Lang::getf('mobile_api.blocker_' . $b['code'], [
                'count' => (string) ($b['count'] ?? ''),
                'amount' => \App\Models\Wallet::formatMoney((int) ($b['amount'] ?? 0)),
            ]),
        ], $blockers);
    }

    /** POST /me/email/resend-verification */
    public function resendVerification(): void
    {
        $uid = $this->requireUserId();
        $users = new User();
        $user = $users->find($uid);
        if (!$user || $users->isEmailVerified($user)) {
            $this->ok(['already_verified' => true]);
        }
        $this->throttle([['verify_resend', 'u' . $uid]]);
        if (!(new AccountService())->sendVerificationEmail($user)) {
            $this->fail('verification_send_failed', 503);
        }
        $this->ok(['sent' => true, 'message' => self::message('verification_sent')]);
    }

    /**
     * POST /auth/apple — Sign in with Apple.
     * Тело: identity_token (JWT от Apple), raw_nonce (одноразовое значение, sha256 которого
     * передано в запрос Apple), given_name/family_name (Apple отдаёт имя только при первом входе).
     */
    public function apple(): void
    {
        $device = $this->requireDevice();
        $this->throttle([['login_ip', AuthThrottle::ip()]]);
        $rawNonce = $this->input('raw_nonce');
        if (strlen($rawNonce) < 16) {
            $this->fail('validation_failed', 422, null, ['raw_nonce' => self::message('field_required')]);
        }
        $cfg = self::mobileConfig();
        $res = (new IdTokenVerifier())->verify(
            $this->input('identity_token'),
            IdTokenVerifier::APPLE,
            $cfg['apple_bundle_ids'] ?? [],
            hash('sha256', $rawNonce)
        );
        if (empty($res['ok'])) {
            ActivityLogger::warning('auth.apple_rejected', 'Sign in with Apple: ' . ($res['error'] ?? ''), null, null, ['reason' => $res['error'] ?? '']);
            $this->fail('external_auth_failed', 401);
        }
        $claims = $res['claims'];
        $name = trim($this->input('given_name') . ' ' . $this->input('family_name'));
        $this->completeExternalLogin('apple', $claims, $name, $device);
    }

    /** POST /auth/google — Google Sign-In (ID-токен из нативного SDK). */
    public function google(): void
    {
        $device = $this->requireDevice();
        $cfg = self::mobileConfig();
        if (empty($cfg['google_client_ids'])) {
            $this->fail('provider_disabled', 503);
        }
        $this->throttle([['login_ip', AuthThrottle::ip()]]);
        $res = (new IdTokenVerifier())->verify($this->input('id_token'), IdTokenVerifier::GOOGLE, $cfg['google_client_ids']);
        if (empty($res['ok'])) {
            ActivityLogger::warning('auth.google_rejected', 'Google Sign-In: ' . ($res['error'] ?? ''), null, null, ['reason' => $res['error'] ?? '']);
            $this->fail('external_auth_failed', 401);
        }
        $claims = $res['claims'];
        $this->completeExternalLogin('google', $claims, trim((string) ($claims['name'] ?? '')), $device);
    }

    /**
     * @param array<string, mixed> $claims проверенные claims ID-токена
     * @param array<string, string> $device
     */
    private function completeExternalLogin(string $provider, array $claims, string $name, array $device): never
    {
        $result = (new AccountService())->resolveExternalIdentity(
            $provider,
            (string) $claims['sub'],
            isset($claims['email']) ? (string) $claims['email'] : null,
            IdTokenVerifier::emailVerified($claims),
            $name,
            $this->input('referral_code')
        );
        if (!$result['ok']) {
            $this->fail($result['error'], 422);
        }
        $user = $result['user'];
        $users = new User();
        if ($users->isBlocked($user)) {
            $this->fail('account_blocked', 403);
        }
        $tokens = new TokenService();
        if ($users->hasTwoFactor($user)) {
            $this->ok([
                'two_factor_required' => true,
                'challenge_token' => $tokens->issueChallenge((int) $user['id'], $device['device_id']),
                'expires_in' => TokenService::CHALLENGE_TTL,
            ]);
        }
        ActivityLogger::info('auth.login', 'Вход через ' . $provider . ' (mobile)', 'user', (int) $user['id'], ['platform' => $device['platform']]);
        $this->ok($this->session($tokens->issue((int) $user['id'], $device), $user) + [
            'account_created' => $result['created'],
            'registration_bonus_points' => $result['bonus'] > 0 ? $result['bonus'] : null,
        ], [], $result['created'] ? 201 : 200);
    }

    /** @return array<string, string> */
    private function requireDevice(): array
    {
        $device = $this->device();
        if ($device['device_id'] === '') {
            $this->fail('device_id_required', 422, null, ['device_id' => self::message('device_id_required')]);
        }

        return $device;
    }

    /** @return array<string, mixed> */
    private static function mobileConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/config/mobile.php';

        return is_file($path) ? (array) (require $path) : [];
    }

    public function twoFactor(): void
    {
        $device = $this->device();
        $tokens = new TokenService();
        $userId = $tokens->verifyChallenge($this->input('challenge_token'), $device['device_id']);
        if ($userId === null) {
            $this->fail('two_factor_expired', 401);
        }
        $this->throttle([['two_factor', 'u' . $userId]]);

        $users = new User();
        $user = $users->find($userId);
        if (!$user || !$users->hasTwoFactor($user) || $users->isBlocked($user)) {
            $this->fail('two_factor_expired', 401);
        }
        $code = $this->input('code');
        $ok = Totp::verify((string) $user['two_factor_secret'], $code) || $users->consumeRecoveryCode($userId, $code);
        if (!$ok) {
            $this->fail('two_factor_invalid', 401);
        }

        ActivityLogger::info('auth.login', 'Вход после 2FA (mobile)', 'user', $userId, ['platform' => $device['platform']]);
        $this->ok($this->session($tokens->issue($userId, $device), $user));
    }

    public function refresh(): void
    {
        $res = (new TokenService())->refresh($this->input('refresh_token'), $this->device());
        if (empty($res['ok'])) {
            $this->fail((string) ($res['error'] ?? 'invalid_refresh_token'), 401);
        }
        $user = (new User())->find((int) $res['user_id']);
        if (!$user || (new User())->isBlocked($user)) {
            (new TokenService())->revokeAllForUser((int) $res['user_id'], 'blocked');
            $this->fail('account_blocked', 403);
        }
        $this->ok($this->session($res['tokens'], $user));
    }

    public function logout(): void
    {
        $refresh = $this->input('refresh_token');
        if ($refresh !== '') {
            (new TokenService())->revokeByRefreshToken($refresh, 'logout');
        } elseif ($this->optionalUserId() !== null && $this->currentFamilyId() !== null) {
            (new TokenService())->revokeFamily((string) $this->currentFamilyId(), 'logout');
        }
        $this->ok(['logged_out' => true]);
    }

    public function logoutAll(): void
    {
        $uid = $this->requireUserId();
        (new TokenService())->revokeAllForUser($uid, 'logout_all');
        ActivityLogger::info('auth.logout_all', 'Выход на всех устройствах (mobile)', 'user', $uid);
        $this->ok(['logged_out' => true]);
    }

    public function me(): void
    {
        $uid = $this->requireUserId();
        $user = (new User())->find($uid);
        $this->ok(['user' => Presenter::me($user ?? [])]);
    }

    public function sessions(): void
    {
        $uid = $this->requireUserId();
        $current = $this->currentFamilyId();
        $list = array_map(static fn (array $s): array => [
            'id' => $s['family_id'],
            'device_name' => $s['device_name'],
            'platform' => $s['platform'],
            'app_version' => $s['app_version'],
            'created_at' => Presenter::date($s['created_at']),
            'last_used_at' => Presenter::date($s['last_used_at']),
            'current' => $s['family_id'] === $current,
        ], (new TokenService())->activeSessions($uid));
        $this->ok(['sessions' => $list]);
    }

    /**
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function session(array $tokens, array $user): array
    {
        return $tokens + ['user' => Presenter::me($user)];
    }

    /** @param list<array{0: string, 1: string}> $pairs */
    private function throttle(array $pairs): void
    {
        $res = (new AuthThrottle())->hitAll($pairs);
        if (empty($res['allowed'])) {
            $retry = (int) ($res['retry_after'] ?? 60);
            $this->fail('too_many_attempts', 429, null, [], ['Retry-After' => (string) $retry]);
        }
    }
}
