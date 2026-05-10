<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SystemSessionSettingsController extends Controller
{
    public function show()
    {
        return response()->json([
            'session_timeout_minutes' => SystemSetting::sessionTimeoutMinutes(),
            'max_login_attempts' => SystemSetting::maxLoginAttempts(),
            'two_factor_auth_enabled' => SystemSetting::twoFactorAuthEnabled(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'session_timeout_minutes' => 'required|integer|min:1|max:10080',
            'max_login_attempts' => 'required|integer|min:3|max:50',
            'two_factor_auth_enabled' => 'required|boolean',
        ]);

        SystemSetting::setSessionTimeoutMinutes((int) $validated['session_timeout_minutes']);
        SystemSetting::setMaxLoginAttempts((int) $validated['max_login_attempts']);
        SystemSetting::setTwoFactorAuthEnabled((bool) $validated['two_factor_auth_enabled']);

        return response()->json([
            'message' => 'Security settings updated.',
            'session_timeout_minutes' => SystemSetting::sessionTimeoutMinutes(),
            'max_login_attempts' => SystemSetting::maxLoginAttempts(),
            'two_factor_auth_enabled' => SystemSetting::twoFactorAuthEnabled(),
        ]);
    }
}
