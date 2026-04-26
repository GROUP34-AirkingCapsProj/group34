<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActivityLog (ERD: activity_log)
 * Table: activity_log
 * Tracks user actions within the system modules.
 */
class ActivityLog extends Model
{
    protected $table = 'activity_log';
    protected $primaryKey = 'activity_id';

    protected $fillable = [
        'user_id',
        'activity_type',
        'module',
        'description',
        'status_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusLookup::class, 'status_id', 'status_id');
    }
}
