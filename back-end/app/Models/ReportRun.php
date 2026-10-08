<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated report. `updated_at` is absent by design — a run is immutable
 * except for pruning, which stamps `file_pruned_at`.
 */
class ReportRun extends Model
{
    protected $table = 'report_runs';
    protected $primaryKey = 'report_run_id';
    public const UPDATED_AT = null;

    protected $fillable = [
        'report_key',
        'title_snapshot',
        'format',
        'parameters',
        'file_path',
        'file_name',
        'file_size_bytes',
        'row_count',
        'generated_by',
        'expires_at',
        'file_pruned_at',
    ];

    protected $casts = [
        'parameters'      => 'array',
        'file_size_bytes' => 'integer',
        'row_count'       => 'integer',
        'created_at'      => 'datetime',
        'expires_at'      => 'datetime',
        'file_pruned_at'  => 'datetime',
    ];

    protected $appends = ['is_expired'];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by', 'user_id');
    }

    /** The file is gone; the row remains so the run can be re-run. */
    public function getIsExpiredAttribute(): bool
    {
        return $this->file_pruned_at !== null;
    }
}
