<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IssuanceSet extends Model
{
    protected $table      = 'issuance_sets';
    protected $primaryKey = 'issuance_set_id';

    protected $fillable = [
        'issuance_id', 'set_id', 'set_quantity',
        'set_name_snapshot', 'was_modified',
    ];

    protected $casts = [
        'set_quantity' => 'integer',
        'was_modified' => 'boolean',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'issuance_id', 'issuance_id');
    }

    /**
     * Includes archived sets on purpose, matching ProductSetLine::product().
     * An issuance still records which BOQ it was built from even after that
     * BOQ is archived; without withTrashed() the link would render nameless
     * instead of showing the actual set the order came from.
     */
    public function productSet(): BelongsTo
    {
        return $this->belongsTo(ProductSet::class, 'set_id', 'set_id')->withTrashed();
    }

    public function details(): HasMany
    {
        return $this->hasMany(IssuanceDetail::class, 'issuance_set_id', 'issuance_set_id');
    }
}
