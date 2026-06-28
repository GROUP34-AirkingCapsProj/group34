<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountAvatarTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Ava',
            'last_name'     => 'Tar',
            'username'      => 'ava_' . $uid,
            'email'         => 'ava_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    public function test_upload_stores_image_and_sets_avatar_path(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ]);

        $resp->assertStatus(200);
        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($resp->json('data.avatar_url'));
    }

    public function test_upload_rejects_non_image(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ]);

        $resp->assertStatus(422);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_upload_rejects_oversized_image(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        // 3 MB — over the 2 MB (2048 KB) limit.
        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('big.jpg')->size(3072),
        ]);

        $resp->assertStatus(422);
    }

    public function test_replacing_avatar_deletes_old_file(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->assertStatus(200);
        $firstPath = $user->fresh()->avatar_path;

        $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ])->assertStatus(200);
        $secondPath = $user->fresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_delete_removes_avatar(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ])->assertStatus(200);
        $path = $user->fresh()->avatar_path;

        $resp = $this->actingAs($user, 'sanctum')->deleteJson('/api/me/avatar');

        $resp->assertStatus(200);
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }
}
