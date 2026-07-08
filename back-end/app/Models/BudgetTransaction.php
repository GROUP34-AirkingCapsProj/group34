<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetTransaction extends Model
{
    protected $table = 'budget_transactions';
    protected $primaryKey = 'txn_id';

    /** Ledger rows are immutable — Eloquent manages created_at only. */
    const UPDATED_AT = null;

    public const TYPE_COMMIT     = 'commit';
    public const TYPE_RELEASE    = 'release';
    public const TYPE_SPEND      = 'spend';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'budget_id',
        'txn_type',
        'amount',
        'pr_id',
        'po_id',
        'performed_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(BudgetAllocation::class, 'budget_id', 'budget_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id', 'pr_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'po_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}
