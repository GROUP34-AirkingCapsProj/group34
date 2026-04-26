<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
class StatusLookup extends Model
{
    use Auditable;
    protected $table = 'status_lookup';
    protected $primaryKey = 'status_id';

    protected $fillable = [
        'status_name',
        'status_category',
    ];

    // Scope for filtering by category
    public function scopeCategory($query, $category)
    {
        return $query->where('status_category', $category);
    }
}