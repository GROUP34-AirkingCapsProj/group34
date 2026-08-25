<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kinds of air conditioner, e.g. Split Type or Window Type.
     *
     * is_two_piece is the DEFAULT piece count for the type, not a rule — a
     * product registered under a two-piece type may still be recorded as a
     * complete unit, and aircon_models carries its own override. See the
     * design spec, decision 6.
     */
    public function up(): void
    {
        Schema::create('ac_type_lookup', function (Blueprint $table) {
            $table->bigIncrements('ac_type_id');
            $table->string('ac_type_name', 60);
            $table->boolean('is_two_piece')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Matches lookupUniqueRule(), which scopes uniqueness to
            // non-archived rows; a plain unique index would block re-using
            // the name of an archived type.
            $table->index('ac_type_name');
        });

        $now = now();

        DB::table('ac_type_lookup')->insert([
            ['ac_type_name' => 'Split Type',     'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Window Type',    'is_two_piece' => false, 'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Floor Standing', 'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Cassette',       'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Ducted',         'is_two_piece' => true,  'created_at' => $now, 'updated_at' => $now],
            ['ac_type_name' => 'Portable',       'is_two_piece' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_type_lookup');
    }
};
