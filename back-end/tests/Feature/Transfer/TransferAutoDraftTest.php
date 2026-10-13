<?php

namespace Tests\Feature\Transfer;

use App\Models\Transfer;

class TransferAutoDraftTest extends TransferTestCase
{
    private function form(string $intent = 'demand'): array
    {
        return ['intent' => $intent, 'fromLocationId' => '', 'toLocationId' => '',
            'transferDate' => '2026-10-13', 'notes' => 'Incomplete but preserved',
            'lines' => [['uid' => 'line-1', 'productId' => '', 'quantity' => '', 'packages' => 0, 'pieces' => 0]]];
    }

    public function test_incomplete_drafts_are_saved_and_visible_in_my_requests_without_moving_stock(): void
    {
        $location = $this->makeLocation('A');
        $user = $this->makeUserAt($location, 'warehouse_personnel');
        $payload = ['draft_key' => 'auto-' . uniqid(), 'form' => $this->form('pull')];
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/transfers/drafts', $payload)->assertOk()
            ->assertJsonPath('data.status.status_name', 'Draft')->assertJsonPath('data.draft_form.notes', 'Incomplete but preserved');
        $id = $response->json('data.transfer_id');
        $this->assertSame(0, Transfer::findOrFail($id)->details()->count());
        $this->getJson('/api/transfers')->assertOk()->assertJsonFragment(['transfer_id' => $id]);
        $this->postJson('/api/transfers/drafts', $payload)->assertOk()->assertJsonPath('data.transfer_id', $id);
        $this->assertSame(1, Transfer::where('draft_key', $payload['draft_key'])->count());
    }

    public function test_submit_reuses_draft_and_delayed_new_draft_saves_cannot_revert_it(): void
    {
        $from = $this->makeLocation('B'); $to = $this->makeLocation('A');
        $user = $this->makeUserAt($to, 'admin');
        $product = $this->makeInstallationMaterial();
        $this->setStock($from->location_id, $product->product_id, 5);
        $form = $this->form();
        $form['fromLocationId'] = (string) $from->location_id;
        $form['toLocationId'] = (string) $to->location_id;
        $form['lines'][0]['productId'] = (string) $product->product_id;
        $form['lines'][0]['quantity'] = '4';
        $payload = ['draft_key' => 'auto-' . uniqid(), 'form' => $form];
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/transfers/drafts', $payload)->assertOk()->json('data.transfer_id');
        $this->postJson('/api/transfers', [
            'draft_id' => $id, 'intent' => 'demand', 'from_location_id' => $from->location_id,
            'to_location_id' => $to->location_id, 'transfer_date' => '2026-10-13',
            'products' => [['product_id' => $product->product_id, 'quantity_requested' => 4]],
        ])->assertCreated()->assertJsonPath('data.transfer_id', $id)->assertJsonPath('data.status.status_name', 'Approved');
        $this->assertNull(Transfer::findOrFail($id)->draft_form);
        $this->postJson('/api/transfers/drafts', $payload)->assertUnprocessable();
        $this->putJson("/api/transfers/{$id}/draft", $payload)->assertUnprocessable();
        $this->assertSame('Approved', Transfer::findOrFail($id)->status->status_name);
    }

    public function test_editing_pending_request_saves_a_draft_without_resubmitting(): void
    {
        $location = $this->makeLocation('A'); $user = $this->makeUserAt($location, 'admin');
        $product = $this->makeInstallationMaterial();
        $transfer = Transfer::create(['intent' => 'pull', 'to_location_id' => $location->location_id,
            'transfer_number' => 'TR-AUTO-' . uniqid(), 'requested_by' => $user->user_id,
            'status_id' => $this->statusId('Pending')]);
        $transfer->details()->create(['product_id' => $product->product_id, 'quantity_requested' => 2]);
        $form = $this->form('pull');
        $form['toLocationId'] = (string) $location->location_id;
        $form['lines'][0]['productId'] = (string) $product->product_id;
        $form['lines'][0]['quantity'] = '7';
        $this->actingAs($user, 'sanctum')->putJson("/api/transfers/{$transfer->transfer_id}/draft", [
            'draft_key' => 'edit-' . uniqid(), 'form' => $form,
        ])->assertOk()->assertJsonPath('data.status.status_name', 'Draft')->assertJsonPath('data.draft_form.lines.0.quantity', '7');
        $this->patchJson("/api/transfers/{$transfer->transfer_id}/request", ['resubmit' => true,
            'products' => [['product_id' => $product->product_id, 'quantity_requested' => 7]],
        ])->assertOk()->assertJsonPath('data.status.status_name', 'Pending')->assertJsonPath('data.draft_form', null);
    }

    public function test_users_cannot_modify_or_submit_someone_elses_draft(): void
    {
        $location = $this->makeLocation('A');
        $owner = $this->makeUserAt($location, 'warehouse_personnel');
        $other = $this->makeUserAt($location, 'warehouse_personnel');
        $payload = ['draft_key' => 'owned-' . uniqid(), 'form' => $this->form('pull')];
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/transfers/drafts', $payload)->assertOk()->json('data.transfer_id');
        $this->actingAs($other, 'sanctum')->postJson('/api/transfers/drafts', $payload)->assertForbidden();
        $this->putJson("/api/transfers/{$id}/draft", $payload)->assertForbidden();
        $product = $this->makeInstallationMaterial();
        $this->postJson('/api/transfers', ['draft_id' => $id, 'intent' => 'pull', 'to_location_id' => $location->location_id,
            'transfer_date' => '2026-10-13', 'products' => [['product_id' => $product->product_id, 'quantity_requested' => 1]],
        ])->assertForbidden();
    }
}
