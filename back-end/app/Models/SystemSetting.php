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
    public const KEY_LABEL_PRINTERS          = 'label_printers';
    public const KEY_BACKUP_SCHEDULE         = 'backup_schedule';
    public const KEY_BACKUP_LAST_RUN         = 'backup_last_run';

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

    // ── Label printers, one per location ──────────────────────

    /** Every field a connection has, with the values a location starts from. */
    public static function labelPrinterDefaults(): array
    {
        return ['transport' => '', 'host' => '', 'port' => 9100, 'share' => '', 'timeout' => 5];
    }

    /**
     * The whole map, for a settings screen that lists every location. Each
     * entry comes back merged over the defaults, same shape as
     * labelPrinter(), so a caller can iterate this without re-merging itself.
     */
    public static function labelPrinters(): array
    {
        $all = static::getValue(self::KEY_LABEL_PRINTERS, []);
        $all = is_array($all) ? $all : [];

        $out = [];
        foreach ($all as $locationId => $entry) {
            $out[$locationId] = array_merge(
                static::labelPrinterDefaults(),
                array_intersect_key(is_array($entry) ? $entry : [], static::labelPrinterDefaults()),
            );
        }

        return $out;
    }

    /**
     * One location's printer. A location nobody has configured returns the
     * defaults untouched, so a caller can treat an empty transport as meaning
     * "no printer configured here" without special-casing an unset location.
     */
    public static function labelPrinter(int $locationId): array
    {
        return static::labelPrinters()[$locationId] ?? static::labelPrinterDefaults();
    }

    /**
     * The map is merged: saving one location's printer leaves every other
     * location's entry untouched, since warehouses are configured one at a
     * time, months apart, and a save that replaced the map would unplug
     * every other warehouse silently.
     *
     * The entry itself is replaced, not merged: the settings form posts a
     * complete record, and an admin retiring a printer with transport: ''
     * must not leave the old host behind for a later partial save to
     * resurrect.
     *
     * Every save also passes every entry back through labelPrinters(), which
     * filters to known keys. So saving warehouse A's printer also re-normalises
     * warehouse B's stored entry, silently dropping any key this class no
     * longer recognises. Harmless today; whoever adds a sixth field should
     * know that old data is normalised on the next unrelated save rather than
     * preserved as-is.
     */
    public static function setLabelPrinter(int $locationId, array $printer): void
    {
        $printer = array_intersect_key($printer, static::labelPrinterDefaults());

        if (! in_array($printer['transport'] ?? '', ['tcp', 'share', ''], true)) {
            $printer['transport'] = '';
        }
        if (array_key_exists('host', $printer)) {
            $printer['host'] = (string) $printer['host'];
        }
        if (array_key_exists('port', $printer)) {
            $printer['port'] = (int) $printer['port'];
        }
        if (array_key_exists('share', $printer)) {
            $printer['share'] = (string) $printer['share'];
        }
        if (array_key_exists('timeout', $printer)) {
            $printer['timeout'] = (int) $printer['timeout'];
        }

        $all              = static::labelPrinters();
        $all[$locationId] = array_merge(static::labelPrinterDefaults(), $printer);

        static::setValue(self::KEY_LABEL_PRINTERS, $all);
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
