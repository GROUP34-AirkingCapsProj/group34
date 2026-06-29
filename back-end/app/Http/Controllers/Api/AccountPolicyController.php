<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class AccountPolicyController extends Controller
{
    public function show()
    {
        return response()->json(SystemSetting::accountPolicy());
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'avatar'  => 'required|boolean',
            'email'   => 'required|boolean',
            'phone'   => 'required|boolean',
            'address' => 'required|boolean',
        ]);

        SystemSetting::setAccountPolicy($validated);

        return response()->json(SystemSetting::accountPolicy());
    }
}
