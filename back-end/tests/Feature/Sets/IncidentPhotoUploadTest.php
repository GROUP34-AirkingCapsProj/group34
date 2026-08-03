<?php

namespace Tests\Feature\Sets;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class IncidentPhotoUploadTest extends SetTestCase
{
    public function test_a_filer_can_upload_a_photo_and_get_back_a_path(): void
    {
        Storage::fake('local');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $res = $this->actingAs($wp, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('damage.jpg'),
        ]);

        $res->assertCreated();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('message', 'Photo uploaded.');

        $path = $res->json('data.path');
        $this->assertStringStartsWith('incidents/', $path);
        Storage::disk('local')->assertExists($path);

        // Pinned against the real regex store() enforces, not a hand-written
        // guess at the shape: incidents/YYYY/MM/<40-char hashName>.<ext>.
        $this->assertMatchesRegularExpression(
            '#^incidents/\d{4}/\d{2}/[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)$#',
            $path
        );
    }

    public function test_a_non_image_is_rejected(): void
    {
        Storage::fake('local');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($wp, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_a_read_only_auditor_cannot_upload(): void
    {
        Storage::fake('local');

        $loc = $this->makeLocation();
        $au  = $this->makeUserAt($loc, 'auditor');

        // Auditors are stopped by the AuditorReadOnly middleware before they
        // ever reach the permission gate — still worth pinning that they
        // can't upload, whichever layer stops them.
        $this->actingAs($au, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('anything.jpg'),
        ])->assertForbidden();
    }

    public function test_a_branch_manager_can_upload(): void
    {
        Storage::fake('local');

        // A branch manager who spots a problem files it themselves, evidence
        // photo included, rather than asking a picker to file on their behalf.
        //
        // NOTE ON A DELIBERATE COVERAGE GAP: this test used to assert the
        // opposite, as the one role-based case that actually reached
        // CheckPermission for incident_reports.create. Now that BM has create,
        // the only role still lacking it is `auditor` — and auditors are
        // stopped by the AuditorReadOnly middleware before CheckPermission ever
        // runs. So no role-based negative case exercises the create permission
        // gate any more. That is known and accepted, not an oversight: the gate
        // itself is covered generically by the permission-middleware tests. If
        // a future role is added without incident_reports.create, add the
        // negative case back here.
        $loc = $this->makeLocation();
        $bm  = $this->makeUserAt($loc, 'branch_manager');

        $res = $this->actingAs($bm, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('anything.jpg'),
        ]);

        $res->assertCreated();
        Storage::disk('local')->assertExists($res->json('data.path'));
    }

    public function test_an_uploaded_photo_round_trips_through_filing(): void
    {
        Storage::fake('local');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $upload = $this->actingAs($wp, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('damage.jpg'),
        ]);
        $upload->assertCreated();
        $photoPath = $upload->json('data.path');

        $filed = $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'reason'      => 'other',
            'quantity'    => 0,
            'narrative'   => 'Evidence photo attached.',
            'photo_path'  => $photoPath,
        ]);

        $filed->assertCreated();
        $filed->assertJsonPath('data.photo_path', $photoPath);
    }
}
