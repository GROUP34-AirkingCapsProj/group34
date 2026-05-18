<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public const KEY_SESSION_TIMEOUT_MINUTES = 'session_timeout_minutes';

    public const KEY_MAX_LOGIN_ATTEMPTS = 'max_login_attempts';

    public const KEY_TWO_FACTOR_AUTH_ENABLED = 'two_factor_auth_enabled';

    public const KEY_COMPANY_NAME    = 'company_name';
    public const KEY_COMPANY_ADDRESS = 'company_address';
    public const KEY_COMPANY_EMAIL   = 'company_email';
    public const KEY_COMPANY_PHONE   = 'company_phone';

    protected $fillable = ['key', 'value'];

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

    public static function setCompany(array $data): void
    {
        $map = [
            'company_name'    => self::KEY_COMPANY_NAME,
            'company_address' => self::KEY_COMPANY_ADDRESS,
            'company_email'   => self::KEY_COMPANY_EMAIL,
            'company_phone'   => self::KEY_COMPANY_PHONE,
        ];
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $data)) {
                $row = static::query()->firstOrNew(['key' => $key]);
                $row->value = (string) $data[$field];
                $row->save();
            }
        }
    }
}
