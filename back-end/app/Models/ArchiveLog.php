<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveLog extends Model
{
    protected $table      = 'archive_logs';
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'triggered_by',
        'triggered_by_user_id',
        'run_type',
        'modules_processed',
        'total_archived',
        'total_deleted',
        'total_skipped',
        'is_dry_run',
        'duration_ms',
        'notes',
    ];

    protected $casts = [
        'modules_processed' => 'array',
        'is_dry_run'        => 'boolean',
    ];

    public function triggeredByUser()
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id', 'user_id');
    }
}
