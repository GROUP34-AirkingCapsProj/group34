# Inventory Batch Console — Refinements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the console to "Inventory Batch", relabel Location→Zone, give the search a real UI, group cards by warehouse/showroom with totals, and fix the Zone-not-persisting-at-receiving bug.

**Architecture:** Mostly frontend edits to `InventoryManagement.jsx` + CSS. Backend adds `warehouse_type` to the PO-groups list and gains a test locking Zone persistence through the scan-receiving path. FIFO grouping/sectioning is derived client-side.

**Tech Stack:** Laravel + React (CRA); PHPUnit feature tests on `airkingV5_test` via `DatabaseTransactions`.

---

## Background / Context

- Console panel: `frontend/src/admin/pages/InventoryManagement.jsx`, guard
  `{activeTab === 'inventory' && inventorySubTab === 'po-console' && (` at line 840; body
  IIFE renders `.po-map-grid` of `.po-slot` cards (lines 840-~944).
- Sub-tab label "Inventory Monitoring Console" at line 481. A **stale** copy of that title
  still sits on the Stock-Level panel heading at line 753 (should read "Stock-Level
  Monitoring").
- PO View modal Overview "Location" field label at line 1502.
- Search pattern to reuse (`sm-search` + magnifier svg): lines 537-547.
- Backend list endpoint groups builder: `back-end/app/Http/Controllers/Api/PoInventoryController.php`
  `index()` — group init sets `warehouse_name => $b->location?->location_name`; add
  `warehouse_type` beside it. `Location` has `location_type` ∈ {warehouse, showroom}.
- Scoping already enforced via `InventoryBatch::forUser()` — do not change.
- Zone-at-receiving chain (all correct on inspection): receive screen
  (`BarcodeScan.jsx` `bsrZoneLabel`) → `inventoryAPI.scanTransaction({..., zone_label})` →
  `InventoryScanTransactionController::store` (validates `zone_label`, stores via
  `createBatch(..., $zoneLabel)` / accumulation branch) → `inventory_batches.zone_label`
  (fillable). Bug is not statically visible → reproduce live (Task 4).

## File Structure

- Modify: `back-end/app/Http/Controllers/Api/PoInventoryController.php` — add `warehouse_type`.
- Modify: `back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php` — assert `warehouse_type`.
- Create: `back-end/tests/Feature/Inventory/ReceivingZonePersistsTest.php` — Zone persistence.
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx` — renames + panel rebuild.
- Modify: `frontend/src/admin/styles/inventory_management.css` — section + search styles.

---

## Task 1: Backend — `warehouse_type` field + Zone-persistence test

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PoInventoryController.php`
- Modify: `back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php`
- Create: `back-end/tests/Feature/Inventory/ReceivingZonePersistsTest.php`

- [ ] **Step 1: Add `warehouse_type` to the list groups**

In `PoInventoryController::index`, the group initializer contains:

```php
                    'warehouse_id'      => $b->location_id,
                    'warehouse_name'    => $b->location?->location_name,
```

Change to:

```php
                    'warehouse_id'      => $b->location_id,
                    'warehouse_name'    => $b->location?->location_name,
                    'warehouse_type'    => $b->location?->location_type,
```

- [ ] **Step 2: Assert it in the existing list test**

In `back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php`, in
`test_lists_one_group_per_po_and_warehouse_with_nested_batches`, after the existing
`$this->assertSame($wh->location_id, $mine['warehouse_id']);` line, add:

```php
        $this->assertSame('warehouse', $mine['warehouse_type']);
```

- [ ] **Step 3: Write the Zone-persistence feature test**

Create `back-end/tests/Feature/Inventory/ReceivingZonePersistsTest.php`. This drives the
same scan-transaction receiving path the "Receive PO" screen uses and asserts the Zone lands
on the batch and surfaces in the console.

```php
<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\InventoryBatch;

class ReceivingZonePersistsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        return User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    public function test_zone_label_from_scan_receiving_persists_to_batch_and_console(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Wire',
            'product_type' => 'installation_material',
            'barcode'      => 'BAR' . random_int(1000000000000, 9999999999999),
        ]);
        $user = $this->makeUser();

        // Approved + verified PO with a line for this product.
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $wh->location_id,
            'verification_status' => 'verified',
            'status_id'           => 8, // Approved
            'order_date'          => now()->toDateString(),
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id'         => $po->po_id,
            'product_id'    => $product->product_id,
            'quantity'      => 10,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $product->barcode,
            'transaction_type' => 'receiving',
            'location_id'      => $wh->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
            'quantity'         => 1,
            'zone_label'       => 'Zone A',
        ]);
        $res->assertStatus(200);
        // The scan engine returns 200 with an action; ensure it was not rejected.
        $this->assertNotSame('rejected', $res->json('data.action'), 'Receiving scan was rejected: ' . json_encode($res->json('data')));

        // The batch created for this receiving carries the Zone.
        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $wh->location_id)
            ->whereNotNull('receiving_id')
            ->latest('batch_id')
            ->first();
        $this->assertNotNull($batch, 'No batch was created for the received product.');
        $this->assertSame('Zone A', $batch->zone_label);

        // And the console surfaces it as the group's location_label.
        $groups = $this->actingAs($user)->getJson('/api/po-inventory-groups')->assertStatus(200)->json('data');
        $mine = collect($groups)->firstWhere('po_id', $po->po_id);
        $this->assertNotNull($mine);
        $this->assertSame('Zone A', $mine['location_label']);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `cd back-end && php artisan test --filter=PoInventoryGroupsTest --filter=ReceivingZonePersistsTest`
(or run them separately). Expected: `PoInventoryGroupsTest` passes (with the new
`warehouse_type` assertion). For `ReceivingZonePersistsTest`:
- If it **PASSES**, the server persists Zone correctly → the bug is client-side; record this
  result for Task 4 (which becomes a frontend reproduction).
- If it **FAILS** on the `$batch->zone_label` assertion, you have reproduced a server-side
  bug: fix `InventoryScanTransactionController::store` so the validated `zone_label` reaches
  `createBatch`/the accumulation branch (it should already — inspect the actual PO-receiving
  branch taken), re-run until green, and note that Task 4's live repro may be unnecessary.

Report the outcome explicitly (pass or fail + which assertion).

- [ ] **Step 5: Lint + commit**

Run: `cd back-end && php -l app/Http/Controllers/Api/PoInventoryController.php`
Expected: `No syntax errors detected`.

```bash
git add back-end/app/Http/Controllers/Api/PoInventoryController.php back-end/tests/Feature/Inventory/PoInventoryGroupsTest.php back-end/tests/Feature/Inventory/ReceivingZonePersistsTest.php
git commit -m "feat(inventory): expose warehouse_type on PO groups + test zone persistence"
```

---

## Task 2: Frontend — small renames (labels + stale title + modal)

**Files:** Modify `frontend/src/admin/pages/InventoryManagement.jsx`.

- [ ] **Step 1: Rename the sub-tab label**

Find (line ~481):

```jsx
              Inventory Monitoring Console
```

inside the `po-console` sub-tab button, and change it to:

```jsx
              Inventory Batch
```

- [ ] **Step 2: Fix the stale Stock-Level panel heading**

Find (line ~753, inside the `stock-level` panel):

```jsx
                  <h3 className="inv-table-title">Inventory Monitoring Console</h3>
                  <p className="inv-table-subtitle">Monitoring {totalItems} SKUs across branches</p>
```

Change the heading to the correct name:

```jsx
                  <h3 className="inv-table-title">Stock-Level Monitoring</h3>
                  <p className="inv-table-subtitle">Monitoring {totalItems} SKUs across branches</p>
```

- [ ] **Step 3: Rename the PO View modal "Location" field to "Zone"**

Find (line ~1502):

```jsx
                          <div className="invd-field"><span className="invd-label">Location</span><span className="invd-value">{poGroupDetail.data.overview.location_label || '—'}</span></div>
```

Change the label to `Zone`:

```jsx
                          <div className="invd-field"><span className="invd-label">Zone</span><span className="invd-value">{poGroupDetail.data.overview.location_label || '—'}</span></div>
```

(Do NOT touch the per-SKU detail modal's "Location"/"Location Type" fields around lines
1209-1214 — those describe the inventory warehouse, a different concept.)

- [ ] **Step 4: Lint + commit**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx`
Expected: 0 errors (pre-existing `warehouses` warning acceptable).

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx
git commit -m "feat(inventory): rename console to Inventory Batch; fix stale title; Zone label in PO modal"
```

---

## Task 3: Frontend — rebuild the console panel (search, Zone naming, warehouse grouping)

**Files:**
- Modify: `frontend/src/admin/pages/InventoryManagement.jsx`
- Modify: `frontend/src/admin/styles/inventory_management.css`

- [ ] **Step 1: Replace the entire PO console panel**

Read the panel block starting at `{activeTab === 'inventory' && inventorySubTab === 'po-console' && (`
(line ~840) through its closing `)}` (just before the Activity Log tab comment). Replace the
WHOLE block with the following. This: renames the heading to "Inventory Batch", swaps the
plain input for the `sm-search` field, relabels the bin tag Zone / "Unzoned" / "e.g. Zone A",
and groups cards into warehouse/showroom sections with header totals and a per-section
"Pick first" flag.

```jsx
        {activeTab === 'inventory' && inventorySubTab === 'po-console' && (
          <div className="po-map-wrap">
            <div className="po-map-head">
              <div>
                <h3 className="inv-table-title">Inventory Batch</h3>
                <p className="inv-table-subtitle">Deliveries laid out by warehouse and zone · oldest first</p>
              </div>
              <div className="sm-search po-search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" />
                </svg>
                <input
                  type="text"
                  placeholder="Search PO, supplier, warehouse, zone…"
                  value={poSearch}
                  onChange={(e) => setPoSearch(e.target.value)}
                />
              </div>
            </div>

            {poGroupsLoading ? (
              <div className="inv-batch-loading">Loading batches…</div>
            ) : poGroupsError ? (
              <div className="inv-batch-empty inv-batch-empty--error">{poGroupsError}</div>
            ) : (() => {
              const q = poSearch.trim().toLowerCase();
              const filtered = q
                ? poGroups.filter((g) => [g.po_number, g.supplier_name, g.warehouse_name, g.location_label]
                    .some((v) => (v || '').toLowerCase().includes(q)))
                : poGroups;

              if (filtered.length === 0) {
                return <div className="po-map-empty">No purchase orders match your search.</div>;
              }

              const byWh = new Map();
              filtered.forEach((g) => {
                if (!byWh.has(g.warehouse_id)) byWh.set(g.warehouse_id, []);
                byWh.get(g.warehouse_id).push(g);
              });
              const sections = [...byWh.values()].sort(
                (a, b) => (a[0].warehouse_name || '').localeCompare(b[0].warehouse_name || '')
              );

              return (
                <div className="po-sections">
                  {sections.map((groups) => {
                    const wh = groups[0];
                    const totalItems = groups.reduce((s, g) => s + (g.item_count || 0), 0);
                    const totalRemaining = groups.reduce((s, g) => s + (g.total_remaining || 0), 0);
                    const totalReceived = groups.reduce((s, g) => s + (g.total_received || 0), 0);
                    const oldest = groups.reduce((min, g) =>
                      (!min || (g.first_received_at || '') < (min.first_received_at || '')) ? g : min, null);
                    const oldestKey = oldest?.group_key;
                    const type = (wh.warehouse_type || '').toLowerCase();
                    return (
                      <section className="po-section" key={wh.warehouse_id}>
                        <div className="po-section-head">
                          <div className="po-section-id">
                            <span className="po-section-name">{wh.warehouse_name || '—'}</span>
                            <span className={`po-section-type po-section-type--${type || 'default'}`}>
                              {wh.warehouse_type || 'Location'}
                            </span>
                          </div>
                          <div className="po-section-totals">
                            <span>{groups.length} deliver{groups.length === 1 ? 'y' : 'ies'}</span>
                            <span>{totalItems} item{totalItems === 1 ? '' : 's'}</span>
                            <span>{totalRemaining} / {totalReceived} in stock</span>
                          </div>
                        </div>
                        <div className="po-map-grid">
                          {groups.map((g) => {
                            const pct = g.total_received > 0
                              ? Math.round((g.total_remaining / g.total_received) * 100)
                              : 0;
                            const isOldest = g.group_key === oldestKey;
                            return (
                              <div key={g.group_key} className={`po-slot${isOldest ? ' po-slot--fifo' : ''}`}>
                                {isOldest && <span className="po-slot-flag">Pick first</span>}
                                <div className="po-slot-top">
                                  {editingLocKey === g.group_key ? (
                                    <div className="po-slot-tag po-slot-tag--edit">
                                      <span className="po-slot-tag-eyebrow">Zone</span>
                                      <input
                                        type="text"
                                        className="po-slot-tag-input"
                                        value={editingLocValue}
                                        onChange={(e) => setEditingLocValue(e.target.value)}
                                        placeholder="e.g. Zone A"
                                        autoFocus
                                        onKeyDown={(e) => { if (e.key === 'Enter') saveLoc(g); if (e.key === 'Escape') setEditingLocKey(null); }}
                                      />
                                      <div className="po-slot-tag-actions">
                                        <button type="button" className="po-slot-tag-save" disabled={savingLoc} onClick={() => saveLoc(g)}>Save</button>
                                        <button type="button" className="po-slot-tag-cancel" disabled={savingLoc} onClick={() => setEditingLocKey(null)}>Cancel</button>
                                      </div>
                                    </div>
                                  ) : (
                                    <button type="button" className="po-slot-tag" onClick={() => startEditingLoc(g)} title="Edit zone">
                                      <span className="po-slot-tag-eyebrow">Zone<span className="po-slot-tag-pencil" aria-hidden="true">✎</span></span>
                                      <span className="po-slot-tag-code">{g.location_label || 'Unzoned'}</span>
                                    </button>
                                  )}
                                  <div className="po-slot-ident">
                                    <span className="po-slot-po">{g.po_number || '—'}</span>
                                    <span className="po-slot-supplier">{g.supplier_name || 'No supplier'}</span>
                                    <span className="po-slot-wh">{g.warehouse_name || '—'}</span>
                                  </div>
                                </div>

                                <div className="po-slot-meta">
                                  <div className="po-slot-meta-item">
                                    <span className="po-slot-meta-label">Received</span>
                                    <span className="po-slot-meta-value">{g.first_received_at || '—'}</span>
                                  </div>
                                  <div className="po-slot-meta-item">
                                    <span className="po-slot-meta-label">Items</span>
                                    <span className="po-slot-meta-value">{g.item_count}</span>
                                  </div>
                                </div>

                                <div className="po-slot-capacity">
                                  <div className="po-slot-bar">
                                    <div className="po-slot-bar-fill" style={{ width: `${pct}%` }} />
                                  </div>
                                  <span className="po-slot-bar-label">{g.total_remaining} / {g.total_received} in stock</span>
                                </div>

                                <div className="po-slot-foot">
                                  <button type="button" className="po-slot-view" onClick={() => openPoGroupDetail(g)}>
                                    View<span aria-hidden="true"> →</span>
                                  </button>
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </section>
                    );
                  })}
                </div>
              );
            })()}
          </div>
        )}
```

- [ ] **Step 2: Add section + search styles**

In `frontend/src/admin/styles/inventory_management.css`, append at the end of the file:

```css
/* Inventory Batch — warehouse/showroom sections */
.po-sections {
  display: flex;
  flex-direction: column;
  gap: 22px;
}
.po-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.po-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding-bottom: 8px;
  border-bottom: 1px solid #eef0f2;
}
.po-section-id {
  display: flex;
  align-items: center;
  gap: 10px;
}
.po-section-name {
  font-size: 14px;
  font-weight: 800;
  color: #111827;
  letter-spacing: 0.2px;
}
.po-section-type {
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 6px;
  background: #eef2ff;
  color: #4338ca;
}
.po-section-type--showroom {
  background: #fdf2f8;
  color: #be185d;
}
.po-section-totals {
  display: flex;
  gap: 16px;
  font-size: 11.5px;
  font-weight: 600;
  color: #6b7280;
}
.po-section-totals span { white-space: nowrap; }
.po-search { max-width: 320px; width: 100%; }
```

- [ ] **Step 3: Lint**

Run: `cd frontend && npx eslint src/admin/pages/InventoryManagement.jsx`
Expected: 0 errors (pre-existing `warehouses` warning acceptable). Fix any real error (most
likely an unbalanced brace/tag from the big block replace — re-check the panel boundaries).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/InventoryManagement.jsx frontend/src/admin/styles/inventory_management.css
git commit -m "feat(inventory): search field, Zone naming, and warehouse/showroom grouping on Inventory Batch"
```

---

## Task 4: Fix the Zone-at-receiving bug (live reproduction)

**Precondition:** Task 1 Step 4 recorded whether the backend `ReceivingZonePersistsTest`
passed. If it **failed**, the fix was already made in Task 1 and this task only needs a quick
confirmation in the app. If it **passed**, the defect is client-side and this task finds it.

This task is done by the orchestrator (uses the run + browser tooling), not a headless
subagent, because it requires driving the receive screen.

- [ ] **Step 1: Reproduce**

Launch the app (prod build or dev server) and sign in. Go to the barcode-scan **Receive PO**
screen, select an Approved/verified PO, type a **Zone** in the "ZONE LABEL" field, and scan
(or enter) an item to receive it.

- [ ] **Step 2: Observe where the Zone is lost**

Check, in order, until the Zone disappears:
1. **Network:** the `POST /api/inventory/scan-transaction` request body — does it include a
   non-empty `zone_label`? If not, the receive UI dropped it (fix: ensure `bsrZoneLabel` is
   bound and sent on the path actually used; check the value is set before the scan fires).
2. **DB:** the newly created `inventory_batches` row for that product/location — is
   `zone_label` set? If the request had it but the row doesn't, fix the controller path that
   handled this receive (the accumulation vs createBatch branch in
   `InventoryScanTransactionController::store`).
3. **Console:** open Inventory → Inventory Batch and confirm the group shows the Zone (not
   "Unzoned").

- [ ] **Step 3: Fix + verify**

Apply the minimal fix at the layer identified in Step 2. Re-run the backend test from Task 1
(`ReceivingZonePersistsTest`) and repeat the live receive to confirm the Zone now shows in
the console.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "fix(inventory): persist Zone entered at PO receiving through to the console"
```

---

## Self-Review Notes

- **Spec coverage:** A (Task 2 Steps 1-2, Task 3 heading), B (Task 2 Step 3, Task 3 card
  Zone/Unzoned), C (Task 3 sm-search), D (Task 1 `warehouse_type`, Task 3 sections + totals +
  per-section FIFO), E (Task 1 test + Task 4 fix) — all covered.
- **Field-name consistency:** frontend reads `warehouse_type`, `warehouse_id`,
  `warehouse_name`, `first_received_at`, `item_count`, `total_remaining`/`total_received`,
  `location_label`, `group_key` — all emitted by the list endpoint.
- **No scoping change; additive backend field.** Renames are UI text only.
- **Task ordering:** Task 2 and Task 3 both touch the panel region but different lines (Task 2:
  sub-tab 481, stale title 753, modal 1502; Task 3: the po-console panel block 840+). Run
  Task 2 before Task 3 to minimize churn.
