<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAlertSettings extends Model
{
    protected $table = 'user_alert_settings';

    protected $fillable = [
        'user_id',
        'pending_approvals_last_seen_at',
    ];

    protected $casts = [
        'pending_approvals_last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get or create alert settings for a user.
     */
    public static function getOrCreateForUser(?int $userId): ?self
    {
        if ($userId === null) {
            return null;
        }
        
        $settings = self::where('user_id', $userId)->first();
        
        if (!$settings) {
            $settings = self::create(['user_id' => $userId]);
        }
        
        return $settings;
    }


    /**
     * Mark pending approvals as seen now.
     */
    public function markPendingApprovalsSeen(): self
    {
        $this->pending_approvals_last_seen_at = now();
        $this->save();
        
        return $this;
    }
}
