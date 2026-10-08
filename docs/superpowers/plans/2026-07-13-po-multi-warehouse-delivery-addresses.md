# PO Multi-Warehouse Delivery Addresses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show every destination warehouse's full address on a PO — one Deliver-To block per warehouse in the detail modal, and one self-contained page (Excel: one sheet) per warehouse in the preview and export — with the manual Ship-To treated as separate header info.

**Architecture:** Backend enriches each PO line with its full location object (the enabling change). The frontend derives the distinct destination warehouses from those line locations via one shared pure helper, reused by the detail modal, the create/convert preview, and both export paths. The export builders are refactored into pure functions (`buildPODocumentHtml`, `buildPOWorkbook`) so the page-splitting logic is unit-testable.

**Tech Stack:** Laravel 10 (PHP) + PHPUnit (`DatabaseTransactions` against `airkingV5_test`); React (CRA) + Jest via `react-scripts test`; `xlsx` (SheetJS) for Excel.

---

## File Structure

- `back-end/app/Http/Controllers/Api/PurchaseOrderController.php` — enrich `transformPoDetail()` with a nested `location` object. (Modify)
- `back-end/tests/Feature/Budget/PoDetailLocationPayloadTest.php` — feature test for the enriched payload. (Create)
- `frontend/src/admin/utils/poExport.js` — add shared `distinctDeliverLocations()` helper; refactor `exportPOToDocument`/`exportPOToExcel` into pure `buildPODocumentHtml()` / `buildPOWorkbook()` that render one page/sheet per warehouse. (Modify)
- `frontend/src/admin/utils/poExport.test.js` — Jest tests for the helper + builders. (Create)
- `frontend/src/admin/pages/PurchaseOrderPage.jsx` — build `ship_to_locations` + split manual Ship-To in the export payload; render per-warehouse Deliver-To blocks in the detail modal; render one page per warehouse in the create/convert preview. (Modify)

---

## Task 1: Backend — enrich `transformPoDetail` with the line's full location

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/PurchaseOrderController.php:568-569`
- Test: `back-end/tests/Feature/Budget/PoDetailLocationPayloadTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Budget/PoDetailLocationPayloadTest.php`:

```php
<?php

namespace Tests\Feature\Budget;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PoDetailLocationPayloadTest extends BudgetTestCase
{
    private function warehouse(string $name, array $extra = []): int
    {
        return (int) DB::table('locations')->insertGetId(array_merge([
            'location_code' => 'WH-' . uniqid(),
            'location_name' => $name,
            'location_type' => 'warehouse',
            'address'       => '123 ' . $name . ' St',
            'city'          => 'Cebu City',
            'province'      => 'Cebu',
            'region'        => 'Region VII',
            'contact_number'=> '032-000-1111',
            'email'         => strtolower($name) . '@wh.local',
            'manager_name'  => 'Mgr ' . $name,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $extra), 'location_id');
    }

    private function product(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
    }

    public function test_show_endpoint_returns_full_location_per_detail_line(): void
    {
        $whA = $this->warehouse('Alpha');
        $whB = $this->warehouse('Bravo');
        $pid = $this->product();
        $supplierId = (int) DB::table('suppliers')->insertGetId(
            ['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id'
        );
        $admin = $this->makeUser('admin');

        $po = PurchaseOrder::create([
            'supplier_id' => $supplierId, 'location_id' => $whA,
            'po_number' => 'PO-' . strtoupper(Str::random(6)),
            'order_date' => today(), 'total_amount' => 500, 'grand_total' => 500,
            'status_id' => 7, 'created_by' => $admin->user_id,
        ]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whA,'product_id'=>$pid,'quantity_ordered'=>2,'unit_price'=>100,'subtotal'=>200]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whB,'product_id'=>$pid,'quantity_ordered'=>3,'unit_price'=>100,'subtotal'=>300]);

        $resp = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/purchase-orders/{$po->po_id}")
            ->assertOk();

        $details = collect($resp->json('data.details'));
        $byLoc = $details->keyBy(fn ($d) => $d['location_id']);

        $this->assertNotNull($byLoc[$whA]['location'], 'line A carries a nested location');
        $this->assertSame('123 Alpha St', $byLoc[$whA]['location']['address']);
        $this->assertSame('Cebu City',    $byLoc[$whA]['location']['city']);
        $this->assertSame('Mgr Bravo',    $byLoc[$whB]['location']['manager_name']);
        $this->assertSame('123 Bravo St', $byLoc[$whB]['location']['address']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd back-end && php artisan test tests/Feature/Budget/PoDetailLocationPayloadTest.php`
Expected: FAIL — `$byLoc[$whA]['location']` is null (the key does not exist yet).

- [ ] **Step 3: Add the nested `location` to `transformPoDetail`**

In `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`, find (around line 567-569):

```php
            // Destination warehouse for this line (per-line warehouse PO shape)
            'location_id'        => $detail->location_id,
            'location_name'      => $detail->location?->location_name,
```

Replace with:

```php
            // Destination warehouse for this line (per-line warehouse PO shape)
            'location_id'        => $detail->location_id,
            'location_name'      => $detail->location?->location_name,
            // Full destination-warehouse location so the UI can show each line's
            // real delivery address (multi-warehouse POs show one block/page each).
            'location'           => $detail->location ? [
                'location_id'    => $detail->location->location_id,
                'location_name'  => $detail->location->location_name,
                'address'        => $detail->location->address,
                'city'           => $detail->location->city,
                'province'       => $detail->location->province,
                'region'         => $detail->location->region,
                'contact_number' => $detail->location->contact_number,
                'email'          => $detail->location->email,
                'manager_name'   => $detail->location->manager_name,
            ] : null,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd back-end && php artisan test tests/Feature/Budget/PoDetailLocationPayloadTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PurchaseOrderController.php back-end/tests/Feature/Budget/PoDetailLocationPayloadTest.php
git commit -m "feat(po): include full destination location on each PO detail line"
```

---

## Task 2: Shared `distinctDeliverLocations` helper

A pure function that turns PO detail rows (enriched, `row.location`) **or** preview line items (`row.location_id` + `row.warehouse_name`, addresses looked up from a `locationsById` map) into a deduped, first-seen-order list of `{ location_id, name, address, contact_number, email, manager_name }`. Reused by the modal, preview, and export.

**Files:**
- Modify: `frontend/src/admin/utils/poExport.js` (top of file, after imports)
- Test: `frontend/src/admin/utils/poExport.test.js` (Create)

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/poExport.test.js`:

```js
import { distinctDeliverLocations } from './poExport';

describe('distinctDeliverLocations', () => {
  test('dedupes enriched PO details by location, first-seen order, joined address', () => {
    const details = [
      { location_id: 5, location: { location_name: 'Alpha', address: '1 A St', city: 'Cebu', province: 'Cebu', region: 'VII', contact_number: '111', email: 'a@w', manager_name: 'MA' } },
      { location_id: 9, location: { location_name: 'Bravo', address: '2 B St', city: 'Mandaue' } },
      { location_id: 5, location: { location_name: 'Alpha' } }, // duplicate of first
    ];
    const out = distinctDeliverLocations(details);
    expect(out).toHaveLength(2);
    expect(out[0]).toEqual({
      location_id: '5', name: 'Alpha', address: '1 A St, Cebu, Cebu, VII',
      contact_number: '111', email: 'a@w', manager_name: 'MA',
    });
    expect(out[1].name).toBe('Bravo');
    expect(out[1].address).toBe('2 B St, Mandaue');
  });

  test('resolves preview line items via locationsById lookup', () => {
    const items = [
      { location_id: 9, warehouse_name: 'Bravo' },
      { location_id: 9, warehouse_name: 'Bravo' },
    ];
    const byId = { 9: { location_name: 'Bravo', address: '2 B St', city: 'Mandaue', contact_number: '222', email: 'b@w', manager_name: 'MB' } };
    const out = distinctDeliverLocations(items, byId);
    expect(out).toHaveLength(1);
    expect(out[0]).toEqual({
      location_id: '9', name: 'Bravo', address: '2 B St, Mandaue',
      contact_number: '222', email: 'b@w', manager_name: 'MB',
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test src/admin/utils/poExport.test.js --watchAll=false`
Expected: FAIL — `distinctDeliverLocations is not a function` (not exported yet).

- [ ] **Step 3: Implement the helper**

In `frontend/src/admin/utils/poExport.js`, add near the top (after the existing imports, before `export function exportPOToDocument`):

```js
// Distinct destination warehouses for a PO, first-seen order, deduped by location id.
// Accepts enriched PO details (row.location = {...}) or preview line items
// (row.location_id + row.warehouse_name) with an optional { [id]: location } lookup
// for addresses. Returns [{ location_id, name, address, contact_number, email, manager_name }].
export function distinctDeliverLocations(rows, locationsById = {}) {
  const map = new Map();
  (rows || []).forEach((r) => {
    const id  = String(r.location_id ?? r.location?.location_id ?? '__none__');
    if (map.has(id)) return;
    const loc = r.location || locationsById[id] || locationsById[String(r.location_id)] || null;
    const address = loc
      ? [loc.address, loc.city, loc.province, loc.region].filter(Boolean).join(', ') || null
      : null;
    map.set(id, {
      location_id:    id,
      name:           loc?.location_name || r.location_name || r.warehouse_name || 'Unassigned warehouse',
      address,
      contact_number: loc?.contact_number || null,
      email:          loc?.email || null,
      manager_name:   loc?.manager_name || null,
    });
  });
  return [...map.values()];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test src/admin/utils/poExport.test.js --watchAll=false`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/poExport.js frontend/src/admin/utils/poExport.test.js
git commit -m "feat(po): add distinctDeliverLocations helper for per-warehouse addresses"
```

---

## Task 3: Export document — one self-contained page per warehouse

Refactor `exportPOToDocument` so the HTML is produced by a pure `buildPODocumentHtml(payload)`. It renders one self-contained page per entry in `payload.ship_to_locations`, separates the manual Ship-To (header) from the per-warehouse Deliver-To, and inserts a page break between pages.

**Files:**
- Modify: `frontend/src/admin/utils/poExport.js:49-267`
- Test: `frontend/src/admin/utils/poExport.test.js`

- [ ] **Step 1: Write the failing test** (append to `poExport.test.js`)

```js
import { buildPODocumentHtml } from './poExport';

const twoWarehousePayload = () => ({
  po_number: 'PO-1',
  company: { name: 'Airking', address: 'HQ Rd', email: 'hq@air' },
  supplier: { name: 'Acme', address: 'Acme Rd' },
  ship_to: { name: 'Airking CDO Branch', address: 'Zone 5, Bulua, CDO' },
  ship_to_locations: [
    { location_id: '5', name: 'Alpha', address: '1 A St, Cebu', contact_number: '111', email: 'a@w', manager_name: 'MA' },
    { location_id: '9', name: 'Bravo', address: '2 B St, Mandaue', contact_number: '222', email: 'b@w', manager_name: 'MB' },
  ],
  items: [
    { location_id: '5', warehouse_name: 'Alpha', product_code: 'A1', name: 'Item A', qty: 2, unit: 'pc', unit_price: 100, subtotal: 200 },
    { location_id: '9', warehouse_name: 'Bravo', product_code: 'B1', name: 'Item B', qty: 3, unit: 'pc', unit_price: 100, subtotal: 300 },
  ],
  total_amount: 500,
});

describe('buildPODocumentHtml', () => {
  test('emits one page per warehouse with its own address and items, plus a page break', () => {
    const html = buildPODocumentHtml(twoWarehousePayload());
    const pages = html.split('class="po-page"').length - 1;
    expect(pages).toBe(2);
    expect(html).toContain('page-break-after');
    // Each warehouse address appears
    expect(html).toContain('1 A St, Cebu');
    expect(html).toContain('2 B St, Mandaue');
    // Manual ship-to header info appears on the pages
    expect(html).toContain('Airking CDO Branch');
    expect(html).toContain('Zone 5, Bulua, CDO');
    // Deliver-To labels reflect each warehouse
    expect(html).toContain('Deliver To (Warehouse): Alpha');
    expect(html).toContain('Deliver To (Warehouse): Bravo');
  });

  test('single warehouse produces one page and no trailing page break', () => {
    const p = twoWarehousePayload();
    p.ship_to_locations = [p.ship_to_locations[0]];
    p.items = [p.items[0]];
    p.total_amount = 200;
    const html = buildPODocumentHtml(p);
    expect(html.split('class="po-page"').length - 1).toBe(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test src/admin/utils/poExport.test.js --watchAll=false`
Expected: FAIL — `buildPODocumentHtml is not a function`.

- [ ] **Step 3: Refactor `exportPOToDocument` into `buildPODocumentHtml` + a thin printer**

In `frontend/src/admin/utils/poExport.js`, replace the whole `export function exportPOToDocument(payload) { ... }` (lines 49-267) with:

```js
export function buildPODocumentHtml(payload) {
  const company  = payload.company  || {};
  const supplier = payload.supplier || {};
  const manual   = payload.ship_to  || {};

  const companyName    = company.name    || 'Airking Air Conditioning';
  const companyAddress = company.address || '';
  const companyEmail   = company.email   || '';
  const supplierName   = (supplier.name || '').toUpperCase();
  const supplierAddr   = supplier.address || '';

  const dateStr      = fmtDate(payload.order_date);
  const paymentTerms = payload.payment_terms || 'Net 30';
  const notes        = payload.notes      || '';
  const refs         = payload.references || '';
  const prepBy       = payload.prepared_by || '';
  const verBy        = payload.verified_by || '';
  const appBy        = payload.approved_by || '';

  const items    = Array.isArray(payload.items) ? payload.items : [];
  const locs     = (Array.isArray(payload.ship_to_locations) && payload.ship_to_locations.length)
    ? payload.ship_to_locations
    : [{ location_id: '__none__', name: manual.name || supplierName, address: manual.address || supplierAddr }];
  const single   = locs.length <= 1;

  const renderItemRow = (item) => {
    const qty    = Number(item.qty ?? item.quantity_ordered ?? 0);
    const price  = Number(item.unit_price || 0);
    const amount = Number(item.subtotal   || qty * price || 0);
    const unit   = item.unit || 'pc';
    const code   = item.product_code || '—';
    const desc   = buildDescription(item);
    const priceStr  = price  === 0 ? 'Free' : price.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    const amountStr = amount === 0 ? '0.00' : amount.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    return `
      <tr>
        <td style="text-align:center">${qty}</td>
        <td style="text-align:center">${unit}</td>
        <td>${code}</td>
        <td>${desc}</td>
        <td style="text-align:right">${priceStr}</td>
        <td style="text-align:right">${amountStr}</td>
      </tr>`;
  };

  const manualBlock = (manual.name || manual.address)
    ? `<div class="ship-to ship-to--manual">
         <strong>Ship To: ${manual.name || ''}</strong>
         ${manual.address ? manual.address : ''}
       </div>`
    : '';

  const pagesHtml = locs.map((loc) => {
    const pageItems = single
      ? items
      : items.filter((it) => String(it.location_id ?? '__none__') === loc.location_id);
    const pageTotal = pageItems.reduce((s, it) => {
      const qty = Number(it.qty ?? it.quantity_ordered ?? 0);
      return s + Number(it.subtotal || qty * Number(it.unit_price || 0) || 0);
    }, 0);
    const deliverExtra = [
      loc.contact_number ? `Phone: ${loc.contact_number}` : '',
      loc.email          ? `Email: ${loc.email}`          : '',
      loc.manager_name   ? `Manager: ${loc.manager_name}` : '',
    ].filter(Boolean).join(' &nbsp;·&nbsp; ');

    return `
  <div class="po-page">
    <div class="company-header">
      <h1>${companyName}</h1>
      ${companyAddress ? `<p>${companyAddress}</p>` : ''}
      ${companyEmail   ? `<p>${companyEmail}</p>`   : ''}
    </div>

    <div class="header-grid">
      <div class="supplier-block">
        <strong>${supplierName}</strong>
        ${supplierAddr}
      </div>
      <div class="po-ref-block">
        <h2>Purchase Order</h2>
        <table class="po-ref-table">
          <tr><td>Date:</td><td>${dateStr}</td></tr>
          <tr><td>Reference #:</td><td>${payload.po_number || ''}</td></tr>
          <tr><td>Payment Terms:</td><td>${paymentTerms}</td></tr>
        </table>
      </div>
    </div>

    ${manualBlock}

    <div class="ship-to">
      <strong>Deliver To (Warehouse): ${loc.name || '—'}</strong>
      ${loc.address ? loc.address : ''}
      ${deliverExtra ? `<div style="font-size:11px;color:#333;margin-top:2px">${deliverExtra}</div>` : ''}
    </div>

    <div class="details-label">Details</div>
    <table class="items-table">
      <thead>
        <tr>
          <th style="text-align:center;width:65px">Quantity</th>
          <th style="text-align:center;width:55px">Unit</th>
          <th style="width:130px">Model Code</th>
          <th>Description</th>
          <th style="text-align:right;width:95px">Unit Price</th>
          <th style="text-align:right;width:95px">Amount</th>
        </tr>
      </thead>
      <tbody>${pageItems.map(renderItemRow).join('')}</tbody>
      <tfoot>
        <tr>
          <td colspan="5" style="text-align:right">Total Amount &rarr;</td>
          <td style="text-align:right">&#8369;${pageTotal.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
        </tr>
      </tfoot>
    </table>

    <div class="notes-section">
      <div>Notes: ${notes}</div>
      <div>References: ${refs}</div>
    </div>

    <div class="signatures">
      <div class="sig-block"><div class="sig-label">Prepared By:</div><div class="sig-name">${prepBy}</div></div>
      <div class="sig-block"><div class="sig-label">Verified By:</div><div class="sig-name">${verBy}</div></div>
      <div class="sig-block"><div class="sig-label">Approved By:</div><div class="sig-name">${appBy}</div></div>
    </div>
  </div>`;
  }).join('');

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Purchase Order - ${payload.po_number || 'PO'}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 13px; color: #000; padding: 40px; max-width: 850px; margin: 0 auto; }
    .po-page { page-break-after: always; }
    .po-page:last-child { page-break-after: auto; }
    .company-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
    .company-header h1 { font-size: 26px; font-weight: 900; letter-spacing: 0.04em; margin-bottom: 4px; text-transform: uppercase; }
    .company-header p { font-size: 11px; color: #333; margin: 2px 0; }
    .header-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .supplier-block { font-size: 13px; line-height: 1.7; }
    .supplier-block strong { font-size: 14px; font-weight: 900; display: block; margin-bottom: 2px; }
    .po-ref-block { text-align: right; }
    .po-ref-block h2 { font-size: 20px; font-weight: 900; margin-bottom: 8px; }
    .po-ref-table { margin-left: auto; border-collapse: collapse; font-size: 13px; }
    .po-ref-table td { padding: 2px 6px; }
    .po-ref-table td:first-child { font-weight: 700; text-align: right; white-space: nowrap; }
    .ship-to { font-size: 13px; line-height: 1.6; margin-bottom: 16px; border: 1.5px solid #000; padding: 8px 12px; background: #f4f4f4; }
    .ship-to--manual { background: #fff; border-style: dashed; }
    .ship-to strong { display: block; font-size: 14px; font-weight: 900; margin-bottom: 2px; text-transform: uppercase; }
    .details-label { font-size: 15px; font-weight: 900; margin-bottom: 4px; }
    .items-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 12px; }
    .items-table th { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 5px 8px; font-weight: 700; text-align: left; }
    .items-table td { padding: 5px 8px; border-bottom: 0.5px solid #ddd; }
    .items-table tfoot td { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-weight: 700; padding: 6px 8px; }
    .notes-section { font-size: 13px; line-height: 2.4; margin: 12px 0 32px; }
    .notes-section div { border-bottom: 1px solid #ccc; padding-bottom: 4px; margin-bottom: 4px; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-top: 32px; }
    .sig-block { display: flex; flex-direction: column; gap: 36px; }
    .sig-label { font-weight: 900; font-size: 13px; }
    .sig-name { font-weight: 900; font-size: 13px; border-bottom: 2px solid #000; padding-bottom: 4px; min-height: 24px; text-decoration: underline; }
    @media print { body { padding: 20px; } }
  </style>
</head>
<body>
${pagesHtml}
</body>
</html>`;
}

export function exportPOToDocument(payload) {
  const html = buildPODocumentHtml(payload);
  const win = window.open('', '_blank', 'width=920,height=720');
  if (!win) {
    alert('Please allow pop-ups to export the PO document.');
    return;
  }
  win.document.write(html);
  win.document.close();
  win.focus();
  setTimeout(() => win.print(), 600);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test src/admin/utils/poExport.test.js --watchAll=false`
Expected: PASS (all describe blocks).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/poExport.js frontend/src/admin/utils/poExport.test.js
git commit -m "feat(po): render one self-contained document page per warehouse"
```

---

## Task 4: Excel export — one sheet per warehouse

Refactor `exportPOToExcel` so the workbook is produced by a pure `buildPOWorkbook(payload)` that creates one worksheet per warehouse (self-contained header + that warehouse's items + its total).

**Files:**
- Modify: `frontend/src/admin/utils/poExport.js:271-386`
- Test: `frontend/src/admin/utils/poExport.test.js`

- [ ] **Step 1: Write the failing test** (append to `poExport.test.js`)

```js
import * as XLSX from 'xlsx';
import { buildPOWorkbook } from './poExport';

describe('buildPOWorkbook', () => {
  const payload = {
    po_number: 'PO-1',
    company: { name: 'Airking' },
    supplier: { name: 'Acme' },
    ship_to: { name: 'Airking CDO Branch', address: 'Zone 5, Bulua, CDO' },
    ship_to_locations: [
      { location_id: '5', name: 'Alpha', address: '1 A St, Cebu' },
      { location_id: '9', name: 'Bravo', address: '2 B St, Mandaue' },
    ],
    items: [
      { location_id: '5', warehouse_name: 'Alpha', product_code: 'A1', qty: 2, unit: 'pc', unit_price: 100, subtotal: 200 },
      { location_id: '9', warehouse_name: 'Bravo', product_code: 'B1', qty: 3, unit: 'pc', unit_price: 100, subtotal: 300 },
    ],
    total_amount: 500,
  };

  test('creates one sheet per warehouse, each carrying its own address', () => {
    const wb = buildPOWorkbook(payload);
    expect(wb.SheetNames).toHaveLength(2);
    expect(wb.SheetNames).toEqual(expect.arrayContaining(['Alpha', 'Bravo']));
    const alphaCsv = XLSX.utils.sheet_to_csv(wb.Sheets['Alpha']);
    expect(alphaCsv).toContain('1 A St, Cebu');
    expect(alphaCsv).toContain('A1');
    expect(alphaCsv).not.toContain('B1'); // Bravo's item not on Alpha's sheet
  });

  test('single warehouse yields a single sheet', () => {
    const p = { ...payload, ship_to_locations: [payload.ship_to_locations[0]], items: [payload.items[0]], total_amount: 200 };
    const wb = buildPOWorkbook(p);
    expect(wb.SheetNames).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx react-scripts test src/admin/utils/poExport.test.js --watchAll=false`
Expected: FAIL — `buildPOWorkbook is not a function`.

- [ ] **Step 3: Refactor `exportPOToExcel` into `buildPOWorkbook` + a thin writer**

In `frontend/src/admin/utils/poExport.js`, replace the whole `export function exportPOToExcel(payload) { ... }` (lines 271-386) with:

```js
// Excel-safe, unique sheet name (max 31 chars, no []:*?/\ ).
function safeSheetName(name, used) {
  let base = String(name || 'Sheet').replace(/[[\]:*?/\\]/g, ' ').slice(0, 31).trim() || 'Sheet';
  let candidate = base;
  let n = 2;
  while (used.has(candidate)) {
    const suffix = ` (${n})`;
    candidate = base.slice(0, 31 - suffix.length) + suffix;
    n += 1;
  }
  used.add(candidate);
  return candidate;
}

function buildPOSheetAoa(payload, loc, sheetItems) {
  const company  = payload.company  || {};
  const supplier = payload.supplier || {};
  const manual   = payload.ship_to  || {};
  const companyName    = company.name    || 'Airking Air Conditioning';
  const companyAddress = company.address || '';
  const companyEmail   = company.email   || '';
  const supplierName   = (supplier.name || '').toUpperCase();
  const supplierAddr   = supplier.address || '';
  const dateStr        = fmtDate(payload.order_date);
  const paymentTerms   = payload.payment_terms || 'Net 30';
  const prepBy = payload.prepared_by || '';
  const verBy  = payload.verified_by || '';
  const appBy  = payload.approved_by || '';
  const notes  = payload.notes      || '';
  const refs   = payload.references || '';

  const aoa = [];
  aoa.push([companyName]);
  if (companyAddress) aoa.push([companyAddress]);
  if (companyEmail)   aoa.push([companyEmail]);
  aoa.push([]);

  aoa.push([supplierName, '', '', 'Purchase Order']);
  aoa.push([supplierAddr, '', '', 'Date:',          dateStr]);
  aoa.push(['',           '', '', 'Reference #:',   payload.po_number || '']);
  aoa.push(['',           '', '', 'Payment Terms:', paymentTerms]);
  if (manual.name || manual.address) {
    aoa.push([`Ship To: ${manual.name || ''}`]);
    if (manual.address) aoa.push([manual.address]);
  }
  aoa.push([`Deliver To (Warehouse): ${loc.name || ''}`]);
  if (loc.address) aoa.push([loc.address]);
  aoa.push([]);

  const COL_HEADERS = ['Quantity', 'Unit', 'Model Code', 'Description', 'Unit Price', 'Amount'];
  aoa.push(COL_HEADERS);
  const headerRowIdx = aoa.length; // 1-based

  let total = 0;
  sheetItems.forEach((item) => {
    const qty    = Number(item.qty ?? item.quantity_ordered ?? 0);
    const price  = Number(item.unit_price || 0);
    const amount = Number(item.subtotal   || qty * price || 0);
    total += amount;
    aoa.push([qty, item.unit || 'pc', item.product_code || '—', buildDescription(item), price, amount]);
  });

  aoa.push([]);
  aoa.push(['', '', '', '', 'TOTAL AMOUNT', total]);
  aoa.push([]);
  aoa.push(['Prepared By:', '', 'Verified By:', '', 'Approved By:', '']);
  aoa.push([prepBy,         '', verBy,          '', appBy,          '']);
  if (notes) { aoa.push([]); aoa.push([`Notes: ${notes}`]); }
  if (refs)  { aoa.push([`References: ${refs}`]); }

  return { aoa, headerRowIdx, COL_HEADERS };
}

export function buildPOWorkbook(payload) {
  const company = payload.company || {};
  const companyName = company.name || 'Airking Air Conditioning';
  const items = Array.isArray(payload.items) ? payload.items : [];
  const locs  = (Array.isArray(payload.ship_to_locations) && payload.ship_to_locations.length)
    ? payload.ship_to_locations
    : [{ location_id: '__none__', name: (payload.ship_to || {}).name || companyName, address: (payload.ship_to || {}).address || '' }];
  const single = locs.length <= 1;

  const wb = XLSX.utils.book_new();
  const usedNames = new Set();

  locs.forEach((loc) => {
    const sheetItems = single
      ? items
      : items.filter((it) => String(it.location_id ?? '__none__') === loc.location_id);
    const { aoa, headerRowIdx, COL_HEADERS } = buildPOSheetAoa(payload, loc, sheetItems);
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [{ wch: 12 }, { wch: 10 }, { wch: 18 }, { wch: 48 }, { wch: 14 }, { wch: 14 }];

    const companyCell = XLSX.utils.encode_cell({ r: 0, c: 0 });
    if (!ws[companyCell]) ws[companyCell] = { v: companyName, t: 's' };
    ws[companyCell].s = { font: { bold: true, sz: 16 } };

    const headerR = headerRowIdx - 1;
    COL_HEADERS.forEach((_, ci) => {
      const addr = XLSX.utils.encode_cell({ r: headerR, c: ci });
      if (ws[addr]) ws[addr].s = { font: { bold: true } };
    });

    XLSX.utils.book_append_sheet(wb, ws, safeSheetName(single ? 'Purchase Order' : loc.name, usedNames));
  });

  return wb;
}

export function exportPOToExcel(payload) {
  const wb = buildPOWorkbook(payload);
  XLSX.writeFile(wb, `${payload.po_number || 'PO'}.xlsx`);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx react-scripts test src/admin/utils/poExport.test.js --watchAll=false`
Expected: PASS (all four describe blocks).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/poExport.js frontend/src/admin/utils/poExport.test.js
git commit -m "feat(po): render one Excel worksheet per warehouse"
```

---

## Task 5: Build `ship_to_locations` + item `location_id` in the export payload

Feed the new export builders. In `PurchaseOrderPage.jsx`, the detail-modal `poPayload` must (a) keep `ship_to` as the **manual** header override only, (b) add `ship_to_locations` from the enriched details, and (c) add `location_id` to each export item.

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx:21` (import) and `:1532-1560` (poPayload)

- [ ] **Step 1: Import the helper**

At line 21, change:

```js
import { exportPOToExcel, exportPOToDocument } from '../utils/poExport';
```

to:

```js
import { exportPOToExcel, exportPOToDocument, distinctDeliverLocations } from '../utils/poExport';
```

- [ ] **Step 2: Update the `poPayload` ship-to + items**

In the `poPayload` object (around line 1532-1560), replace the `ship_to: { ... }` block:

```js
                  ship_to: {
                    name:    d.ship_to_name    || d.location?.location_name,
                    address: d.ship_to_address || [d.location?.address, d.location?.city, d.location?.province, d.location?.region].filter(Boolean).join(', ') || null,
                    contact_number: d.location?.contact_number,
                    email:          d.location?.email,
                    manager_name:   d.location?.manager_name,
                  },
```

with (manual header info only — no location fallback):

```js
                  // Manual Ship-To = header info only (never a delivery destination).
                  ship_to: {
                    name:    d.ship_to_name    || null,
                    address: d.ship_to_address || null,
                  },
                  // Real per-warehouse delivery destinations (one page/sheet each).
                  ship_to_locations: (() => {
                    const locs = distinctDeliverLocations(details);
                    if (locs.length && !(locs.length === 1 && locs[0].location_id === '__none__')) return locs;
                    // Legacy single-warehouse PO: fall back to the header location.
                    return [{
                      location_id:    String(d.location?.location_id ?? '__none__'),
                      name:           d.location?.location_name || d.ship_to_name || '—',
                      address:        [d.location?.address, d.location?.city, d.location?.province, d.location?.region].filter(Boolean).join(', ') || d.ship_to_address || null,
                      contact_number: d.location?.contact_number || null,
                      email:          d.location?.email || null,
                      manager_name:   d.location?.manager_name || null,
                    }];
                  })(),
```

- [ ] **Step 3: Add `location_id` to each export item**

In the same `poPayload`, find the `items: details.map(item => ({` block (around line 1545) and add `location_id` as the first mapped field:

```js
                  items: details.map(item => ({
                    location_id:       String(item.location_id ?? item.location?.location_id ?? '__none__'),
                    product_code:      item.product?.applianceDetail?.model_code || item.model_code || '—',
```

(leave the remaining item fields unchanged).

- [ ] **Step 4: Manual verification — export a real PO**

Run the app (`cd frontend && npm run demo` per project convention, or the dev server), open a PO that spans two warehouses, and click **Export → Document** and **Export → Excel**.
Expected: the document opens with **two pages** (one per warehouse), each showing that warehouse's real address in the Deliver-To box and the manual Ship-To in the header; the Excel file has **two sheets** named for each warehouse. A single-warehouse PO exports one page / one sheet.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): supply per-warehouse ship_to_locations to PO exports"
```

---

## Task 6: PO detail modal — one Deliver-To block per warehouse

Replace the single "Deliver To (Warehouse)" block with one block per distinct destination warehouse.

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx:1411-1418`

- [ ] **Step 1: Replace the Deliver-To block**

Replace the entire second `<div className="po-detail-address-block">` (lines 1411-1418, the "Deliver To (Warehouse)" block) with:

```jsx
                  {(() => {
                    const deliverLocs = distinctDeliverLocations(details);
                    const blocks = (deliverLocs.length && !(deliverLocs.length === 1 && deliverLocs[0].location_id === '__none__'))
                      ? deliverLocs
                      : [{
                          location_id:    String(d.location?.location_id ?? '__none__'),
                          name:           d.location?.location_name || '—',
                          address:        [d.location?.address, d.location?.city, d.location?.province, d.location?.region].filter(Boolean).join(', ') || null,
                          contact_number: d.location?.contact_number || null,
                          email:          d.location?.email || null,
                          manager_name:   d.location?.manager_name || null,
                        }];
                    return (
                      <div className="po-detail-address-block">
                        <div className="po-detail-address-block-title">
                          Deliver To (Warehouse){blocks.length > 1 ? ` — ${blocks.length} destinations` : ''}
                        </div>
                        {blocks.map((loc, i) => (
                          <div key={loc.location_id + '-' + i} style={i > 0 ? { marginTop: 10, paddingTop: 10, borderTop: '1px dashed #cbd5e1' } : undefined}>
                            <div className="po-detail-address-row"><span className="po-detail-address-label">Warehouse</span><span className="po-detail-address-value po-detail-address-value--name">{loc.name}</span></div>
                            {loc.address        && <div className="po-detail-address-row"><span className="po-detail-address-label">Address</span><span className="po-detail-address-value">{loc.address}</span></div>}
                            {loc.contact_number && <div className="po-detail-address-row"><span className="po-detail-address-label">Phone</span><span className="po-detail-address-value">{loc.contact_number}</span></div>}
                            {loc.email          && <div className="po-detail-address-row"><span className="po-detail-address-label">Email</span><span className="po-detail-address-value">{loc.email}</span></div>}
                            {loc.manager_name   && <div className="po-detail-address-row"><span className="po-detail-address-label">Manager</span><span className="po-detail-address-value">{loc.manager_name}</span></div>}
                          </div>
                        ))}
                      </div>
                    );
                  })()}
```

- [ ] **Step 2: Manual verification**

Open a two-warehouse PO's detail modal.
Expected: the "Deliver To (Warehouse) — 2 destinations" block shows two stacked address blocks (separated by a dashed divider), each with its own warehouse name and full address. A one-warehouse PO shows a single block identical to before.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): show every destination warehouse address in PO detail modal"
```

---

## Task 7: Create/convert preview — one page per warehouse

Render the on-screen document preview as one self-contained "page" card per warehouse, mirroring the export.

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` — the preview block (`~1134-1210`), using `deliverWarehouseNames`/`groupedByWarehouse`/`locations` already in scope.

> **Before editing, Read the full preview block** (from `return ( <div className="po-doc-preview">` ~line 1134 down to where the preview `</div>` closes, past line 1210). It currently has a **single** totals/notes/signatures region *after* the one items table. Because each per-warehouse page below renders its **own** total row, you must move notes/signatures to render **once after** the pages loop (or per page, self-contained) and **remove the old single grand-total row** so the total is not duplicated. Preserve the exact original line-item description markup (brand/capacity/variant, model code, unit) — the cells shown below are a minimal sketch.

- [ ] **Step 1: Build a locations lookup + per-warehouse pages in the preview**

Inside the `showPreview && (() => { ... })()` block (starts ~line 1110), just before `return ( <div className="po-doc-preview">`, add:

```js
                const locationsById = Object.fromEntries(
                  (locations || []).map(l => [String(l.location_id ?? l.id), l])
                );
                const previewLocs = (() => {
                  const rows = isNewShapePR
                    ? lineItems.map(li => ({ location_id: li.location_id, warehouse_name: li.warehouse_name }))
                    : [{ location_id: selectedPR?.location?.location_id, warehouse_name: selectedPR?.location?.location_name }];
                  const out = distinctDeliverLocations(rows, locationsById);
                  return out.length ? out : [{ location_id: '__none__', name: shipToName || '—', address: shipToAddress || null }];
                })();
                const previewItemsFor = (loc) => (
                  isNewShapePR
                    ? lineItems.filter(li => String(li.location_id ?? '__none__') === loc.location_id)
                    : lineItems
                );
```

- [ ] **Step 2: Replace the single Deliver-To box + items table with a per-warehouse page loop**

Replace the block from `{/* Deliver To (Warehouse) — where the supplier delivers each item */}` (line 1161) through the end of the line-items `</table>` (line ~1207, just before the totals/notes region) with a `previewLocs.map(...)` that renders one page section each:

```jsx
                    {previewLocs.map((loc, pi) => {
                      const pageItems = previewItemsFor(loc);
                      const pageTotal = pageItems.reduce((s, li) => s + (Number(li.subtotal) || Number(li.quantity) * Number(li.unit_price) || 0), 0);
                      return (
                        <div key={loc.location_id + '-' + pi} style={pi > 0 ? { marginTop: 24, paddingTop: 24, borderTop: '2px dashed #94a3b8' } : undefined}>
                          {previewLocs.length > 1 && (
                            <div style={{ fontSize: 11, fontWeight: 700, color: '#64748b', textTransform: 'uppercase', marginBottom: 6 }}>
                              Page {pi + 1} of {previewLocs.length}
                            </div>
                          )}

                          {/* Manual Ship-To = header info (same on every page) */}
                          {(shipToName || shipToAddress) && (
                            <div style={{ marginBottom: 10, fontSize: 12, lineHeight: 1.5, border: '1px dashed #94a3b8', padding: '6px 10px' }}>
                              <strong style={{ display: 'block', textTransform: 'uppercase' }}>Ship To: {shipToName || ''}</strong>
                              {shipToAddress && <div>{shipToAddress}</div>}
                            </div>
                          )}

                          {/* Deliver To = this warehouse's real address */}
                          <div className="po-doc-ship-to" style={{ marginBottom: 16, fontSize: 13, lineHeight: 1.6, border: '1.5px solid #000', padding: '8px 12px', background: '#f4f4f4' }}>
                            <strong style={{ display: 'block', marginBottom: 2, textTransform: 'uppercase' }}>
                              Deliver To (Warehouse): {loc.name || '—'}
                            </strong>
                            {loc.address && <div>{loc.address}</div>}
                          </div>

                          <div className="po-doc-details-label">Details</div>
                          <table className="po-doc-table">
                            <thead>
                              <tr>
                                <th>Quantity</th><th>Unit</th><th>Model Code</th><th>Description</th><th>Unit Price</th><th>Amount</th>
                              </tr>
                            </thead>
                            <tbody>
                              {pageItems.map((li, idx) => (
                                <tr key={`${pi}-${idx}`}>
                                  <td>{li.quantity}</td>
                                  <td>{li.unit_abbreviation || (li.product_type === 'appliance' ? 'unit' : li.unit_name || 'pc')}</td>
                                  <td>{li.product?.applianceDetail?.model_code || li.model_code || '—'}</td>
                                  <td>{li.product_name || li.name || '—'}</td>
                                  <td>₱{Number(li.unit_price || 0).toLocaleString('en-PH')}</td>
                                  <td>₱{Number(li.subtotal || (li.quantity * li.unit_price) || 0).toLocaleString('en-PH')}</td>
                                </tr>
                              ))}
                              <tr>
                                <td colSpan={5} style={{ textAlign: 'right', fontWeight: 700 }}>Total Amount →</td>
                                <td style={{ fontWeight: 700 }}>₱{pageTotal.toLocaleString('en-PH')}</td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      );
                    })}
```

Note: verify the exact original preview line-item cell markup (lines 1188-1206) and preserve any fields it rendered that are not shown above (e.g. brand/capacity/variant description); reuse the existing `poLineLabel`-style description if the original used one, rather than the simplified `product_name` cell.

- [ ] **Step 3: Manual verification**

Start a PO conversion from a PR that spans two warehouses; open **Preview Document**.
Expected: the preview shows two "Page 1 of 2" / "Page 2 of 2" sections, each with the manual Ship-To in the header, that warehouse's real Deliver-To address, and only that warehouse's line items + its own total. A single-warehouse conversion shows one page (no "Page x of y" label).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): preview one self-contained page per destination warehouse"
```

---

## Final verification

- [ ] **Backend suite:** `cd back-end && php artisan test tests/Feature/Budget` — expected: all green (existing + new `PoDetailLocationPayloadTest`).
- [ ] **Frontend unit:** `cd frontend && npx react-scripts test src/admin/utils/poExport.test.js --watchAll=false` — expected: all green.
- [ ] **End-to-end (manual):** with a live two-warehouse PO, confirm all three surfaces (detail modal, preview, Document + Excel export) show both warehouse addresses, the manual Ship-To stays header-only, and a single-warehouse PO is unchanged.
