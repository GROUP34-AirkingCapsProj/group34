<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupLog extends Model
{
    protected $table = 'backup_logs';
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'action',
        'triggered_by',
        'triggered_by_user_id',
        'status',
        'file_path',
        'file_name',
        'file_size_bytes',
        'checksum_sha256',
        'restored_from_log_id',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'duration_ms'     => 'integer',
    ];

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id', 'user_id');
    }

    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_log_id', 'log_id');
    }
}
