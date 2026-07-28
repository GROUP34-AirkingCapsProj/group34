<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('receivings')
            ->where(function ($query) {
                $query->where('receiving_number', 'like', 'RCV-SCAN-%')
                    ->orWhere('receiving_number', 'like', 'PENDING-%');
            })
            ->orderBy('receiving_id')
            ->eachById(function ($receiving) {
                $date = $receiving->receiving_date
                    ? date('Ymd', strtotime($receiving->receiving_date))
                    : date('Ymd');

                DB::table('receivings')
                    ->where('receiving_id', $receiving->receiving_id)
                    ->update([
                        'receiving_number' => sprintf(
                            'GRN-%s-%06d',
                            $date,
                            (int) $receiving->receiving_id
                        ),
                    ]);
            }, 100, 'receiving_id');
    }

    public function down(): void
    {
        // Readable GRN numbers are permanent identifiers and must not be
        // converted back to the former hash-like format.
    }
};
