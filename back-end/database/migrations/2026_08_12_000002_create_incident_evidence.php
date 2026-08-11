<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's five-item attachment checklist.
 *
 * incident_reports carries exactly ONE photo_path today; their form requires
 * five distinct pictures. Each row is one requirement, either satisfied by a
 * photo or explicitly skipped with a written reason — a skip is data, not an
 * absence, so the admin can see that the box photo is missing BECAUSE the box
 * was already discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->bigIncrements('evidence_id');

            $table->unsignedBigInteger('incident_id');
            $table->string('requirement', 40);   // defect_photo|model_serial_sticker|box_two_sides|accessories_warranty|asc_service_report

            // Private `local` disk, like the existing evidence photo — these may
            // show named staff, stock layout, or the subject of a live loss
            // investigation, so they are served through an authenticated route.
            $table->string('photo_path', 255)->nullable();
            // Client-asserted: a camera capture and a gallery pick arrive as
            // identical uploads, so this is recorded, never trusted.
            $table->string('photo_source', 20)->nullable();

            $table->text('skipped_reason')->nullable();

            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->index(['incident_id', 'requirement']);
            $table->foreign('incident_id')->references('incident_id')->on('incident_reports');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_evidence');
    }
};
