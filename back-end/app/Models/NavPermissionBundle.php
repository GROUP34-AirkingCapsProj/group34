<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NavPermissionBundle extends Model
{
    protected $primaryKey = 'bundle_id';

    protected $fillable = [
        'name', 'route_path', 'sidebar_section', 'sidebar_icon', 'description', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'nav_bundle_permissions',
            'bundle_id',
            'permission_id'
        )->withPivot('is_required');
    }
}
