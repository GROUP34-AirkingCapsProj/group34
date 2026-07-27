<?php

use App\Models\WarrantyRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('issuance_details as d')
            ->join('issuances as i', 'i.issuance_id', '=', 'd.issuance_id')
            ->join('appliance_details as a', 'a.product_id', '=', 'd.product_id')
            ->leftJoin('warranty_records as w', 'w.issuance_detail_id', '=', 'd.issuance_detail_id')
            ->where('i.status_id', 31)
            ->whereNotNull('d.serial_number')
            ->whereNull('w.warranty_id')
            ->where(function ($query) {
                $query->where('a.warranty_period_months', '>', 0)
                    ->orWhere('a.warranty_value', '>', 0);
            })
            ->orderBy('d.issuance_detail_id')
            ->select([
                'd.issuance_detail_id', 'd.issuance_id', 'd.product_id',
                'd.serial_number', 'i.customer_id', 'i.issuance_date',
                'a.warranty_period_months', 'a.warranty_value', 'a.warranty_unit',
            ])
            ->each(function ($row) {
                $months = (int) $row->warranty_period_months;
                if ($months <= 0) {
                    $value = (int) $row->warranty_value;
                    $months = $row->warranty_unit === 'year' ? $value * 12 : $value;
                }
                if ($months <= 0) return;

                $saleDate = Carbon::parse($row->issuance_date ?: now())->toDateString();
                WarrantyRecord::updateOrCreate(
                    ['issuance_detail_id' => $row->issuance_detail_id],
                    [
                        'issuance_id' => $row->issuance_id,
                        'product_id' => $row->product_id,
                        'serial_number' => $row->serial_number,
                        'customer_id' => $row->customer_id,
                        'sale_date' => $saleDate,
                        'warranty_start_date' => $saleDate,
                        'warranty_end_date' => Carbon::parse($saleDate)->addMonths($months)->toDateString(),
                        'warranty_period_months' => $months,
                        'warranty_duration_label' => $months % 12 === 0
                            ? (($months / 12) . ' ' . ($months === 12 ? 'Year' : 'Years'))
                            : ($months . ' ' . ($months === 1 ? 'Month' : 'Months')),
                        'status_id' => 33,
                    ]
                );
            });
    }

    public function down(): void
    {
        // Historical warranty records are business data and must not be deleted.
    }
};
