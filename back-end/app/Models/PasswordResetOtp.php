<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Password-reset OTP challenges only.
 * Login 2FA continues to use users.otp_code / users.otp_expires_at.
 */
class PasswordResetOtp extends Model
{
    protected $table = 'password_reset_otps';

    protected $fillable = [
        'email',
        'otp_hash',
        'expires_at',
        'verified_token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'otp_hash',
        'verified_token',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return filled($this->verified_token);
    }
}
