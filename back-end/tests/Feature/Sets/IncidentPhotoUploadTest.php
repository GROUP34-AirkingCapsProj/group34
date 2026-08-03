<?php

namespace Tests\Feature\Sets;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class IncidentPhotoUploadTest extends SetTestCase
{
    public function test_a_filer_can_upload_a_photo_and_get_back_a_path(): void
    {
        Storage::fake('public');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $res = $this->actingAs($wp, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('damage.jpg'),
        ]);

        $res->assertCreated();
        $path = $res->json('data.path');
        $this->assertStringStartsWith('incidents/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_non_image_is_rejected(): void
    {
        Storage::fake('public');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($wp, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_a_read_only_auditor_cannot_upload(): void
    {
        Storage::fake('public');

        $loc = $this->makeLocation();
        $au  = $this->makeUserAt($loc, 'auditor');

        $this->actingAs($au, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('anything.jpg'),
        ])->assertForbidden();
    }
}
