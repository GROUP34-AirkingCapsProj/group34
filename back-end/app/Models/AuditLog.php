<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog (ERD: audit_log)
 * Table: audit_log
 * Fields: audit_id (id), user_id FK, action, table_affected, record_id, timestamp
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    protected $fillable = [
        'user_id',
        'action',
        'table_affected',
        'record_id',
        'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'record_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
