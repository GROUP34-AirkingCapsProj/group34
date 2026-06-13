<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NavPermissionBundle extends Model
{
    protected $table = 'nav_permission_bundles';
    protected $primaryKey = 'bundle_id';

    protected $fillable = [
        'name',
        'route_path',
        'sidebar_section',
        'sidebar_icon',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function permissions()
    {
        return $this->belongsToMany(
            Permission::class,
            'nav_bundle_permissions',
            'bundle_id',
            'permission_id'
        )->withPivot('is_required')->withTimestamps();
    }
}
