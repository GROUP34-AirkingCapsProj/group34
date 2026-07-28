<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    public const KEY_SESSION_TIMEOUT_MINUTES = 'session_timeout_minutes';
    public const KEY_MAX_LOGIN_ATTEMPTS      = 'max_login_attempts';
    public const KEY_TWO_FACTOR_AUTH_ENABLED = 'two_factor_auth_enabled';
    public const KEY_COMPANY_NAME            = 'company_name';
    public const KEY_COMPANY_ADDRESS         = 'company_address';
    public const KEY_COMPANY_EMAIL           = 'company_email';
    public const KEY_COMPANY_PHONE           = 'company_phone';
    public const KEY_PDF_PAPER_SIZE          = 'pdf_paper_size';
    public const KEY_ARCHIVE_CONFIG          = 'archive_config';
    public const KEY_ARCHIVE_SCHEDULE        = 'archive_schedule';
    public const KEY_ARCHIVE_NOTIFICATIONS   = 'archive_notifications';
    public const KEY_ARCHIVE_LAST_RUN        = 'archive_last_run';
    public const KEY_ACCOUNT_POLICY          = 'account_self_service_policy';

    protected $fillable = ['key', 'value'];

    // ── Generic get/set ───────────────────────────────────────

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        if (! $row) {
            return $default;
        }
        $raw     = $row->value;
        $decoded = json_decode($raw, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
    }

    public static function setValue(string $key, mixed $value): void
    {
        $stored = (is_array($value) || is_object($value))
            ? json_encode($value, JSON_UNESCAPED_UNICODE)
            : (string) $value;

        static::updateOrCreate(['key' => $key], ['value' => $stored]);
        Cache::forget("system_setting_{$key}");
    }

    // ── Archive config helpers ────────────────────────────────

    public static function getArchiveConfig(string $module): array
    {
        $all = static::getValue(self::KEY_ARCHIVE_CONFIG, []);
        return $all[$module] ?? [
            'auto_archive_enabled' => false,
            'archive_after_days'   => null,
            'archivable_statuses'  => [],
            'retention_days'       => null,
            'require_reason'       => false,
            'pinnable'             => false,
        ];
    }

    public static function setArchiveConfig(string $module, array $config): void
    {
        $all            = static::getValue(self::KEY_ARCHIVE_CONFIG, []);
        $all[$module]   = array_merge($all[$module] ?? [], $config);
        static::setValue(self::KEY_ARCHIVE_CONFIG, $all);
    }

    public static function sessionTimeoutMinutes(): int
    {
        $raw = static::query()->where('key', self::KEY_SESSION_TIMEOUT_MINUTES)->value('value');
        $n = is_numeric($raw) ? (int) $raw : 30;

        return max(1, min(10080, $n));
    }

    public static function setSessionTimeoutMinutes(int $minutes): void
    {
        $minutes = max(1, min(10080, $minutes));

        $row = static::query()->firstOrNew(['key' => self::KEY_SESSION_TIMEOUT_MINUTES]);
        $row->value = (string) $minutes;
        $row->save();
    }

    public static function maxLoginAttempts(): int
    {
        $raw = static::query()->where('key', self::KEY_MAX_LOGIN_ATTEMPTS)->value('value');
        $n = is_numeric($raw) ? (int) $raw : 5;

        return max(3, min(50, $n));
    }

    public static function setMaxLoginAttempts(int $attempts): void
    {
        $attempts = max(3, min(50, $attempts));

        $row = static::query()->firstOrNew(['key' => self::KEY_MAX_LOGIN_ATTEMPTS]);
        $row->value = (string) $attempts;
        $row->save();
    }

    public static function twoFactorAuthEnabled(): bool
    {
        $raw = static::query()->where('key', self::KEY_TWO_FACTOR_AUTH_ENABLED)->value('value');

        if ($raw === null) {
            return true;
        }

        $s = strtolower(trim((string) $raw));

        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }

    public static function setTwoFactorAuthEnabled(bool $enabled): void
    {
        $row = static::query()->firstOrNew(['key' => self::KEY_TWO_FACTOR_AUTH_ENABLED]);
        $row->value = $enabled ? '1' : '0';
        $row->save();
    }

    public static function companyName(): string
    {
        return (string) (static::query()->where('key', self::KEY_COMPANY_NAME)->value('value') ?? 'Airking Air Conditioning');
    }

    public static function companyAddress(): string
    {
        return (string) (static::query()->where('key', self::KEY_COMPANY_ADDRESS)->value('value') ?? '');
    }

    public static function companyEmail(): string
    {
        return (string) (static::query()->where('key', self::KEY_COMPANY_EMAIL)->value('value') ?? '');
    }

    public static function companyPhone(): string
    {
        return (string) (static::query()->where('key', self::KEY_COMPANY_PHONE)->value('value') ?? '');
    }

    public static function pdfPaperSize(): string
    {
        $size = strtolower((string) static::getValue(self::KEY_PDF_PAPER_SIZE, 'a4'));
        return in_array($size, ['a4', 'letter', 'legal', 'a5'], true) ? $size : 'a4';
    }

    public static function setCompany(array $data): void
    {
        $map = [
            'company_name'    => self::KEY_COMPANY_NAME,
            'company_address' => self::KEY_COMPANY_ADDRESS,
            'company_email'   => self::KEY_COMPANY_EMAIL,
            'company_phone'   => self::KEY_COMPANY_PHONE,
            'pdf_paper_size'  => self::KEY_PDF_PAPER_SIZE,
        ];
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $data)) {
                $row = static::query()->firstOrNew(['key' => $key]);
                $row->value = (string) $data[$field];
                $row->save();
            }
        }
    }

    /**
     * Which self-service fields non-admin users may edit, merged over an
     * all-true default so missing keys never lock a field unexpectedly.
     */
    public static function accountPolicy(): array
    {
        $defaults = ['avatar' => true, 'email' => true, 'phone' => true, 'address' => true];
        $stored   = static::getValue(self::KEY_ACCOUNT_POLICY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $out = $defaults;
        foreach ($defaults as $key => $_) {
            if (array_key_exists($key, $stored)) {
                $out[$key] = (bool) $stored[$key];
            }
        }
        return $out;
    }

    public static function setAccountPolicy(array $policy): void
    {
        $clean = [];
        foreach (['avatar', 'email', 'phone', 'address'] as $key) {
            $clean[$key] = array_key_exists($key, $policy) ? (bool) $policy[$key] : true;
        }
        static::setValue(self::KEY_ACCOUNT_POLICY, $clean);
    }
}
