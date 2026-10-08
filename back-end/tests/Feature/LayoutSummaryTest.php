<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LayoutSummaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_layout_summary_returns_badge_and_awaiting_counts(): void
    {
        $user = User::query()->whereNotNull('role_id')->first();
        $this->assertNotNull($user, 'Seeded user required for this test.');

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/layout-summary');

        $res->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'badge_count',
                    'unread_count',
                    'total_pending',
                    'pending_pr_count',
                    'awaiting_fulfillment',
                    'awaiting_receipt',
                ],
            ]);
    }

    public function test_layout_summary_requires_auth(): void
    {
        $this->getJson('/api/layout-summary')->assertUnauthorized();
    }
}
