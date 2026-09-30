<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerDriftFinding extends Model
{
    protected $table = 'ledger_drift_findings';
    protected $primaryKey = 'finding_id';

    protected $fillable = [
        'check_type', 'source_type', 'period_start', 'period_end', 'scope',
        'expected', 'actual', 'variance', 'detail', 'detected_at',
        'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'scope'        => 'array',
        'period_start' => 'date',
        'period_end'   => 'date',
        'detected_at'  => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by', 'user_id');
    }
}
