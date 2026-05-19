<?php

namespace App\Traits;

use App\Models\AuditTrail;
use Carbon\Carbon;

trait Auditable
{
    protected static array $skipAuditKeys = [
        // Auth / security — never log these
        'password',
        'password_hash',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'otp_code',
        'otp_expires_at',
        'email_verified_at',
        // Timestamps — not useful in audit context
        'created_at',
        'updated_at',
        'deleted_at',
        // Internal Laravel field
        'laravel_through_key',
    ];
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            static::logAudit('create', $model);
        });

        static::updated(function ($model) {
            static::logAudit('update', $model);
        });

        static::deleted(function ($model) {
            static::logAudit('delete', $model);
        });
    }

    protected static function logAudit(string $action, $model): void
    {
        $user = auth()->user();

        if (!$user) {
            return;
        }

        $oldValues = null;
        $newValues = null;

        if ($action === 'update') {
            // getDirty() uses cast-aware comparison, preventing false positives for date fields
            $dirtyKeys = array_keys($model->getDirty());

            if (empty($dirtyKeys)) {
                return;
            }

            $flip = array_flip($dirtyKeys);
            $changedNew = array_intersect_key($model->getAttributes(), $flip);
            $changedOld = array_intersect_key($model->getOriginal(), $flip);

            $oldValues = static::normalizeAuditValues($changedOld);
            $newValues = static::normalizeAuditValues($changedNew);
        } elseif ($action === 'create') {
            $newValues = static::normalizeAuditValues($model->getAttributes());
        } elseif ($action === 'delete') {
            $oldValues = static::normalizeAuditValues($model->getOriginal());
        }

        try {
            AuditTrail::create([
                'user_id'    => $user->user_id,
                'action'     => $action,
                'table_name' => $model->getTable(),
                'record_id'  => $model->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected static function normalizeAuditValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (\in_array($key, static::$skipAuditKeys, true)) {
                continue;
            }
            $normalized[$key] = static::resolveAuditValue($key, $value);
        }
        return $normalized;
    }

    protected static function resolveAuditValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Normalize datetime strings to clean date (YYYY-MM-DD)
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value)) {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        if (!is_numeric($value)) {
            return $value;
        }

        $id = (int) $value;

        try {
            // status_id → status name
            if ($key === 'status_id') {
                $row = \App\Models\StatusLookup::find($id);
                return $row ? $row->status_name : $value;
            }

            // user FK fields → full name
            if (in_array($key, ['approved_by', 'created_by', 'received_by', 'issued_by',
                                 'transferred_by', 'adjusted_by', 'requested_by', 'returned_by',
                                 'user_id'])) {
                $user = \App\Models\User::find($id);
                if ($user) {
                    $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                    return $name !== '' ? $name : $user->username;
                }
                return $value;
            }

            // supplier_id → supplier name
            if ($key === 'supplier_id') {
                $row = \App\Models\Supplier::find($id);
                return $row ? $row->supplier_name : $value;
            }

            // location_id → location name
            if ($key === 'location_id') {
                $row = \App\Models\Location::find($id);
                return $row ? $row->location_name : $value;
            }

            // product_id → product name
            if ($key === 'product_id') {
                $row = \App\Models\Product::find($id);
                return $row ? $row->product_name : $value;
            }

            // budget_id → period label
            if ($key === 'budget_id') {
                $row = \App\Models\BudgetAllocation::find($id);
                return $row ? $row->period_label : $value;
            }

            // pr_id → PR number
            if ($key === 'pr_id') {
                $row = \App\Models\PurchaseRequest::find($id);
                return $row ? $row->pr_number : $value;
            }

            // po_id → PO number
            if ($key === 'po_id') {
                $row = \App\Models\PurchaseOrder::find($id);
                return $row ? $row->po_number : $value;
            }

            // receiving_id → receiving number
            if ($key === 'receiving_id') {
                $row = \App\Models\Receiving::find($id);
                return $row ? $row->receiving_number : $value;
            }

            // issuance_id → issuance identifier
            if ($key === 'issuance_id') {
                $row = \App\Models\Issuance::find($id);
                return $row ? "Issuance #{$id}" : $value;
            }

            // customer_id → customer name
            if ($key === 'customer_id') {
                $row = \App\Models\Customer::find($id);
                return $row ? $row->customer_name : $value;
            }

            // adjustment_id → adjustment number
            if ($key === 'adjustment_id') {
                $row = \App\Models\Adjustment::find($id);
                return $row ? ($row->adjustment_number ?? "Adjustment #{$id}") : $value;
            }

            // category_id → category name
            if ($key === 'category_id') {
                $row = \App\Models\CategoryLookup::find($id);
                return $row ? $row->category_name : $value;
            }

            // brand_id → brand name
            if ($key === 'brand_id') {
                $row = \App\Models\BrandLookup::find($id);
                return $row ? $row->brand_name : $value;
            }

            // unit_id → unit name
            if ($key === 'unit_id') {
                $row = \App\Models\UnitLookup::find($id);
                return $row ? $row->unit_name : $value;
            }

        } catch (\Throwable $e) {
            report($e);
        }

        return $value;
    }
}
