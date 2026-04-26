<?php

namespace App\Traits;

use App\Models\AuditTrail;

trait Auditable
{
    protected static function bootAuditable()
    {
        static::created(function ($model) {
            self::logAudit('create', $model);
        });

        static::updated(function ($model) {
            self::logAudit('update', $model);
        });

        static::deleted(function ($model) {
            self::logAudit('delete', $model);
        });
    }

    protected static function logAudit($action, $model)
    {
        $user = auth()->user();
        
        if (!$user) {
            return;
        }

        $oldValues = null;
        $newValues = null;

        if ($action === 'update') {
            $oldValues = $model->getOriginal();
            $newValues = $model->getAttributes();
            
            // Remove unchanged attributes
            $changed = [];
            foreach ($newValues as $key => $value) {
                if (isset($oldValues[$key]) && $oldValues[$key] != $value) {
                    $changed[$key] = $value;
                }
            }
            $newValues = $changed;
        } elseif ($action === 'create') {
            $newValues = $model->getAttributes();
        } elseif ($action === 'delete') {
            $oldValues = $model->getOriginal();
        }

        try {
            AuditTrail::create([
                'user_id' => $user->user_id,
                'action' => $action,
                'table_name' => $model->getTable(),
                'record_id' => $model->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            // Don't fail the main action if audit logging fails
        }
    }
}