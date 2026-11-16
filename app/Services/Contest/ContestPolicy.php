<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Core\Auth;
use App\Models\Setting;

/**
 * Модуль «Конкурсы и розыгрыши»: флаги, разрешённые механики, статусная машина, справочники.
 *
 * Юридический принцип (MASTERING-01, раздел K): все механики определения результата
 * по умолчанию ВЫКЛЮЧЕНЫ и включаются администратором только после юридического заключения.
 * Платное участие, участие с покупкой, продажа шансов и доп. шансы за приглашения
 * в коде не реализованы вовсе.
 */
final class ContestPolicy
{
    // ── Флаги (таблица settings) ─────────────────────────────────────────────
    public const SETTING_ENABLED = 'contests_enabled';
    public const SETTING_ORGANIZER_MODE = 'contests_organizer_mode';
    public const SETTING_REQUIRE_VERIFIED_EMAIL = 'contests_require_verified_email';
    public const SETTING_METHOD_PREFIX = 'contests_method_';      // + mechanic + _enabled

    // ── Механики определения результата ──────────────────────────────────────
    /** Конкурс: победителей выбирает организатор (жюри) по опубликованным критериям. */
    public const MECHANIC_JURY = 'jury';
    /** Розыгрыш: проверяемый случайный выбор (commit–reveal). */
    public const MECHANIC_RANDOM = 'random';
    public const MECHANICS = [self::MECHANIC_JURY, self::MECHANIC_RANDOM];

    // ── Кто может быть организатором ──────────────────────────────────────────
    public const ORGANIZER_BUSINESS_VERIFIED = 'business_verified';
    public const ORGANIZER_ANY = 'any';
    public const ORGANIZER_STAFF_ONLY = 'staff_only';
    public const ORGANIZER_MODES = [self::ORGANIZER_BUSINESS_VERIFIED, self::ORGANIZER_ANY, self::ORGANIZER_STAFF_ONLY];

    // ── Статусы акции ─────────────────────────────────────────────────────────
    public const S_DRAFT = 'draft';
    public const S_PENDING = 'pending_moderation';
    public const S_REJECTED = 'rejected';
    public const S_SCHEDULED = 'scheduled';
    public const S_ACTIVE = 'active';
    public const S_CLOSED = 'closed';
    public const S_RESULTS = 'results_published';
    public const S_COMPLETED = 'completed';
    public const S_SUSPENDED = 'suspended';
    public const S_CANCELLED = 'cancelled';
    public const S_DISPUTED = 'disputed';

    public const STATUSES = [
        self::S_DRAFT, self::S_PENDING, self::S_REJECTED, self::S_SCHEDULED, self::S_ACTIVE,
        self::S_CLOSED, self::S_RESULTS, self::S_COMPLETED, self::S_SUSPENDED, self::S_CANCELLED, self::S_DISPUTED,
    ];

    /** Статусы, видимые публично (каталог и страница акции). */
    public const PUBLIC_STATUSES = [self::S_SCHEDULED, self::S_ACTIVE, self::S_CLOSED, self::S_RESULTS, self::S_COMPLETED, self::S_DISPUTED];

    /** Статусы, в которых организатор может редактировать акцию. */
    public const EDITABLE_STATUSES = [self::S_DRAFT, self::S_REJECTED];

    /**
     * Разрешённые переходы: from => [to => [кто может]].
     * Роли: organizer, moderator (право contests), admin, system (cron/сервер).
     */
    public const TRANSITIONS = [
        self::S_DRAFT => [
            self::S_PENDING => ['organizer', 'admin'],
            self::S_CANCELLED => ['organizer', 'admin'],
        ],
        self::S_REJECTED => [
            self::S_PENDING => ['organizer', 'admin'],
            self::S_CANCELLED => ['organizer', 'admin'],
        ],
        self::S_PENDING => [
            self::S_SCHEDULED => ['moderator', 'admin'],
            self::S_ACTIVE => ['moderator', 'admin'],
            self::S_REJECTED => ['moderator', 'admin'],
            self::S_CANCELLED => ['organizer', 'admin'],
        ],
        self::S_SCHEDULED => [
            self::S_ACTIVE => ['system', 'admin'],
            self::S_SUSPENDED => ['moderator', 'admin'],
            self::S_CANCELLED => ['organizer', 'admin'],
        ],
        self::S_ACTIVE => [
            self::S_CLOSED => ['system', 'admin'],
            self::S_SUSPENDED => ['moderator', 'admin'],
            self::S_CANCELLED => ['admin'],
        ],
        self::S_SUSPENDED => [
            self::S_SCHEDULED => ['moderator', 'admin'],
            self::S_ACTIVE => ['moderator', 'admin'],
            self::S_CLOSED => ['moderator', 'admin'],
            self::S_CANCELLED => ['admin'],
        ],
        self::S_CLOSED => [
            self::S_RESULTS => ['organizer', 'admin', 'system'],
            self::S_SUSPENDED => ['moderator', 'admin'],
            self::S_CANCELLED => ['admin'],
        ],
        self::S_RESULTS => [
            self::S_COMPLETED => ['system', 'admin'],
            self::S_DISPUTED => ['moderator', 'admin'],
        ],
        self::S_DISPUTED => [
            self::S_RESULTS => ['moderator', 'admin'],
            self::S_CANCELLED => ['admin'],
        ],
        self::S_COMPLETED => [
            self::S_DISPUTED => ['moderator', 'admin'],
        ],
    ];

    // ── Условия участия (только проверяемые внутренние) ──────────────────────
    public const COND_EMAIL_VERIFIED = 'email_verified';
    public const COND_ACCOUNT_AGE = 'account_age_days';
    public const COND_FOLLOW_ORGANIZER = 'follow_organizer';
    public const COND_SUBMISSION = 'submission';
    public const CONDITIONS = [
        self::COND_EMAIL_VERIFIED,
        self::COND_ACCOUNT_AGE,
        self::COND_FOLLOW_ORGANIZER,
        self::COND_SUBMISSION,
    ];

    // ── Ограничения полей ─────────────────────────────────────────────────────
    public const MAX_WINNERS = 50;
    public const MAX_DURATION_DAYS = 90;
    public const MIN_DURATION_HOURS = 1;
    public const MAX_ACCOUNT_AGE_DAYS = 365;
    public const MAX_SUBMISSION_CHARS = 2000;
    public const DEFAULT_FULFILMENT_DAYS = 14;
    public const MAX_FULFILMENT_DAYS = 60;
    /** Порог совпадений IP-хэша внутри одной акции для пометки «на проверку». */
    public const FRAUD_SAME_IP_THRESHOLD = 3;

    public const REPORT_REASONS = ['fraud', 'misleading', 'prize_not_received', 'prohibited', 'unfair_result', 'other'];

    public const REGIONS = [
        'kz', 'astana', 'almaty_city', 'shymkent', 'abai', 'akmola', 'aktobe', 'almaty_region', 'atyrau',
        'east_kz', 'zhambyl', 'zhetisu', 'west_kz', 'karaganda', 'kostanay', 'kyzylorda', 'mangystau',
        'pavlodar', 'north_kz', 'turkistan', 'ulytau',
    ];

    public function __construct(private ?Setting $settings = null)
    {
    }

    public function moduleEnabled(): bool
    {
        return $this->bool(self::SETTING_ENABLED, false);
    }

    public function mechanicEnabled(string $mechanic): bool
    {
        return in_array($mechanic, self::MECHANICS, true)
            && $this->bool(self::SETTING_METHOD_PREFIX . $mechanic . '_enabled', false);
    }

    /** @return list<string> */
    public function enabledMechanics(): array
    {
        return array_values(array_filter(self::MECHANICS, fn (string $m): bool => $this->mechanicEnabled($m)));
    }

    public function organizerMode(): string
    {
        $mode = (string) ($this->settings()->get(self::SETTING_ORGANIZER_MODE) ?? '');
        return in_array($mode, self::ORGANIZER_MODES, true) ? $mode : self::ORGANIZER_BUSINESS_VERIFIED;
    }

    public function requireVerifiedEmail(): bool
    {
        return $this->bool(self::SETTING_REQUIRE_VERIFIED_EMAIL, true);
    }

    /**
     * Может ли пользователь создавать акции. Возвращает null, если может, иначе код причины.
     */
    public function organizerDenyReason(?array $user): ?string
    {
        if ($user === null) {
            return 'login';
        }
        if (!empty($user['blocked_at'])) {
            return 'blocked';
        }
        $isStaff = in_array((string) ($user['role'] ?? ''), ['admin', 'manager'], true);
        if (!$this->moduleEnabled() && (string) ($user['role'] ?? '') !== 'admin') {
            return 'module_disabled';
        }
        if ($this->enabledMechanics() === []) {
            return 'no_mechanics';
        }
        if (($user['aml_status'] ?? '') === 'AML_BLOCKED') {
            return 'aml';
        }
        return match ($this->organizerMode()) {
            self::ORGANIZER_ANY => ($this->requireVerifiedEmail() && empty($user['email_verified_at'])) ? 'email_unverified' : null,
            self::ORGANIZER_STAFF_ONLY => $isStaff ? null : 'staff_only',
            default => (($user['account_type'] ?? '') === 'business' && ($user['business_status'] ?? '') === 'verified') || $isStaff
                ? null
                : 'business_required',
        };
    }

    /** Публичная видимость модуля (каталог). Админ видит всегда. */
    public function publicVisible(): bool
    {
        return $this->moduleEnabled() || Auth::isAdmin();
    }

    /**
     * @param list<string> $roles роли текущего актёра
     */
    public static function canTransition(string $from, string $to, array $roles): bool
    {
        $allowed = self::TRANSITIONS[$from][$to] ?? null;
        if ($allowed === null) {
            return false;
        }
        return array_intersect($allowed, $roles) !== [];
    }

    public static function isPublicStatus(string $status): bool
    {
        return in_array($status, self::PUBLIC_STATUSES, true);
    }

    public static function isEditable(string $status): bool
    {
        return in_array($status, self::EDITABLE_STATUSES, true);
    }

    public static function canModerate(): bool
    {
        return Auth::can('contests');
    }

    private function bool(string $key, bool $default): bool
    {
        try {
            $v = $this->settings()->getBool($key);
        } catch (\Throwable) {
            return $default;
        }
        return $v ?? $default;
    }

    private function settings(): Setting
    {
        return $this->settings ??= new Setting();
    }
}
