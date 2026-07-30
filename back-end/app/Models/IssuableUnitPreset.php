<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IssuableUnitPreset extends Model
{
    use SoftDeletes;

    protected $table = 'issuable_unit_preset';
    protected $primaryKey = 'preset_id';

    protected $fillable = [
        'label',
        'unit_id',
        'default_conversion_factor',
        'is_whole_package',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'unit_id'                   => 'integer',
        'default_conversion_factor' => 'float',
        'is_whole_package'          => 'boolean',
        'sort_order'                => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitLookup::class, 'unit_id', 'unit_id');
    }
}
