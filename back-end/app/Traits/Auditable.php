<?php

namespace App\Traits;

use App\Models\AuditTrail;
use Carbon\Carbon;

trait Auditable
{
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
        } catch (\Throwable $e) {
            report($e);
        }

        return $value;
    }
}
