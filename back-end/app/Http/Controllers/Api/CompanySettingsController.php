<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class CompanySettingsController extends Controller
{
    public function show()
    {
        return response()->json([
            'company_name'    => SystemSetting::companyName(),
            'company_address' => SystemSetting::companyAddress(),
            'company_email'   => SystemSetting::companyEmail(),
            'company_phone'   => SystemSetting::companyPhone(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'company_name'    => 'sometimes|string|max:255',
            'company_address' => 'sometimes|string|max:500',
            'company_email'   => 'sometimes|email|max:255',
            'company_phone'   => 'sometimes|string|max:50',
        ]);

        SystemSetting::setCompany($validated);

        return response()->json([
            'message'         => 'Company settings updated.',
            'company_name'    => SystemSetting::companyName(),
            'company_address' => SystemSetting::companyAddress(),
            'company_email'   => SystemSetting::companyEmail(),
            'company_phone'   => SystemSetting::companyPhone(),
        ]);
    }
}
