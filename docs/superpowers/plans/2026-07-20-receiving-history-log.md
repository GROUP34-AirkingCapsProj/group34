# Receiving History Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a persistent, PO-grouped receiving log ("History" tab) to the Receive PO scan screen, so receivers can browse past receiving activity across all purchase orders and drill into the existing per-PO history timeline.

**Architecture:** One new backend endpoint (`GET /receivings/po-log`) groups `Receiving` rows by `po_id` (scoped by the existing `forUser` location trait), returning one summarized row per PO. A new frontend component (`ReceivingHistoryLog.jsx`) renders that log with search/warehouse/date filters and pagination, mounted as a second tab inside the existing "Select PO" screen in `BarcodeScan.jsx`. Clicking a row reuses the exact `openPoHistory` pattern already shipped in `PurchaseOrderPage.jsx` to open the existing `RecordHistoryModal`. The `getStatusConfig` status→label/color helper, previously private to `PurchaseOrderPage.jsx`, is extracted to a shared util so `BarcodeScan.jsx` can use it too.

**Tech Stack:** Laravel 12 (Eloquent aggregates: `withCount`/`withMax`/`withSum`), React (function components, hooks), existing `ApiResponse::paginated()` response shape, PHPUnit `DatabaseTransactions` feature tests against the seeded `airkingV5_test` database.

---

### Task 1: Backend — `poLog` endpoint on `ReceivingController`

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ReceivingController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/History/ReceivingPoLogTest.php`

- [ ] **Step 1: Write the failing tests**

Create `back-end/tests/Feature/History/ReceivingPoLogTest.php`:

```php
<?php

namespace Tests\Feature\History;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReceivingPoLogTest extends TestCase
{
    use DatabaseTransactions;

    /** role_id 1/2/5 are centralized (isCentralized()); 4 is warehouse_personnel (location-scoped). */
    private function makeUser(int $roleId, ?int $locationId = null): User
    {
        return User::forceCreate([
            'first_name' => 'Log', 'last_name' => 'User',
            'email' => 'rlog_' . uniqid() . '@test.local',
            'username' => 'rlog_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id' => $roleId,
            'location_id' => $locationId,
        ]);
    }

    private function makeLocation(string $name): Location
    {
        return Location::forceCreate([
            'location_code' => 'LOG-' . uniqid(), 'location_name' => $name,
            'location_type' => 'warehouse', 'is_active' => 1,
        ]);
    }

    private function makePoWithReceivings(User $u, Location $loc, Supplier $sup, int $count): PurchaseOrder
    {
        $prod = Product::forceCreate([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Log Widget',
            'product_type' => 'installation_material',
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-LOG-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        for ($i = 0; $i < $count; $i++) {
            $r = Receiving::create([
                'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
                'location_id' => $loc->location_id, 'receiving_number' => 'RCV-' . uniqid(),
                'receiving_date' => today()->subDays($count - $i)->toDateString(),
                'received_by' => $u->user_id, 'total_quantity_received' => 5,
            ]);
            ReceivingDetail::create([
                'receiving_id' => $r->receiving_id, 'product_id' => $prod->product_id,
                'quantity_amount' => 5, 'prod_price' => 10, 'condition' => 'Good',
            ]);
        }
        return $po;
    }

    public function test_groups_multiple_receivings_into_one_log_entry(): void
    {
        $u = $this->makeUser(1);
        $loc = $this->makeLocation('Log WH A');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $po = $this->makePoWithReceivings($u, $loc, $sup, 3);

        $res = $this->actingAs($u, 'sanctum')->getJson('/api/receivings/po-log');
        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('po_id', $po->po_id);
        $this->assertNotNull($row);
        $this->assertSame(3, $row['events_count']);
        $this->assertSame(15, $row['total_items']);
    }

    public function test_location_id_filter_narrows_results(): void
    {
        $u = $this->makeUser(1);
        $locA = $this->makeLocation('Log WH A');
        $locB = $this->makeLocation('Log WH B');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $poA = $this->makePoWithReceivings($u, $locA, $sup, 1);
        $poB = $this->makePoWithReceivings($u, $locB, $sup, 1);

        $res = $this->actingAs($u, 'sanctum')->getJson('/api/receivings/po-log?location_id=' . $locA->location_id);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertTrue($ids->contains($poA->po_id));
        $this->assertFalse($ids->contains($poB->po_id));
    }

    public function test_date_range_filter_narrows_results(): void
    {
        $u = $this->makeUser(1);
        $loc = $this->makeLocation('Log WH C');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $prod = Product::forceCreate([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'Log Widget',
            'product_type' => 'installation_material',
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $sup->supplier_id, 'location_id' => $loc->location_id,
            'po_number' => 'PO-LOG-' . uniqid(), 'order_date' => today()->toDateString(),
            'verification_status' => 'verified', 'total_amount' => 100, 'grand_total' => 100,
            'created_by' => $u->user_id,
        ]);
        $old = Receiving::create([
            'po_id' => $po->po_id, 'supplier_id' => $sup->supplier_id,
            'location_id' => $loc->location_id, 'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => today()->subDays(30)->toDateString(),
            'received_by' => $u->user_id, 'total_quantity_received' => 5,
        ]);
        ReceivingDetail::create([
            'receiving_id' => $old->receiving_id, 'product_id' => $prod->product_id,
            'quantity_amount' => 5, 'prod_price' => 10, 'condition' => 'Good',
        ]);

        $res = $this->actingAs($u, 'sanctum')
            ->getJson('/api/receivings/po-log?from_date=' . today()->subDays(2)->toDateString());
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertFalse($ids->contains($po->po_id));
    }

    public function test_search_matches_po_number(): void
    {
        $u = $this->makeUser(1);
        $loc = $this->makeLocation('Log WH D');
        $sup = Supplier::forceCreate(['supplier_name' => 'UniqueSupplierName' . uniqid()]);
        $po = $this->makePoWithReceivings($u, $loc, $sup, 1);

        $res = $this->actingAs($u, 'sanctum')
            ->getJson('/api/receivings/po-log?search=' . urlencode($po->po_number));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertTrue($ids->contains($po->po_id));
    }

    public function test_location_scoped_user_only_sees_their_locations_pos(): void
    {
        $locA = $this->makeLocation('Log WH Scoped A');
        $locB = $this->makeLocation('Log WH Scoped B');
        $sup = Supplier::forceCreate(['supplier_name' => 'Log Supplier ' . uniqid()]);
        $admin = $this->makeUser(1);
        $poA = $this->makePoWithReceivings($admin, $locA, $sup, 1);
        $poB = $this->makePoWithReceivings($admin, $locB, $sup, 1);

        $scopedUser = $this->makeUser(4, $locA->location_id);

        $res = $this->actingAs($scopedUser, 'sanctum')->getJson('/api/receivings/po-log');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('po_id');
        $this->assertTrue($ids->contains($poA->po_id));
        $this->assertFalse($ids->contains($poB->po_id));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd back-end && php artisan test --filter=ReceivingPoLogTest`
Expected: FAIL — route `/api/receivings/po-log` does not exist (404 on every test).

- [ ] **Step 3: Add the route**

In `back-end/routes/api.php`, find these lines (around line 565-567):

```php
    Route::get('/receivings', [ReceivingController::class, 'index']);
    Route::get('/receivings/po-scan-status/{poId}', [ReceivingController::class, 'poScanStatus']);
    Route::get('/receivings/{id}', [ReceivingController::class, 'show']);
```

Replace with (new route added before the `{id}` wildcard so it isn't swallowed by it):

```php
    Route::get('/receivings', [ReceivingController::class, 'index']);
    Route::get('/receivings/po-scan-status/{poId}', [ReceivingController::class, 'poScanStatus']);
    Route::get('/receivings/po-log', [ReceivingController::class, 'poLog']);
    Route::get('/receivings/{id}', [ReceivingController::class, 'show']);
```

- [ ] **Step 4: Implement `poLog` in `ReceivingController`**

In `back-end/app/Http/Controllers/Api/ReceivingController.php`, add this method directly after `index()` (which ends at line 47, just before `public function store(Request $request)`):

```php
    /**
     * Persistent receiving log, grouped by purchase order (not one row per scan).
     * Every Receiving row is one scan; a busy PO can have dozens, but every row's
     * click-through leads to the same PO-level history timeline, so this groups
     * them into one summarized entry per PO.
     */
    public function poLog(Request $request)
    {
        try {
            $user = $request->user();

            $applyReceivingFilters = function ($q) use ($request, $user) {
                $q->forUser($user);
                if ($request->filled('location_id')) {
                    $q->where('location_id', $request->location_id);
                }
                if ($request->filled('from_date')) {
                    $q->whereDate('receiving_date', '>=', $request->from_date);
                }
                if ($request->filled('to_date')) {
                    $q->whereDate('receiving_date', '<=', $request->to_date);
                }
            };

            $poIdsQuery = Receiving::query();
            $applyReceivingFilters($poIdsQuery);
            $poIds = $poIdsQuery->distinct()->pluck('po_id');

            $query = PurchaseOrder::query()
                ->whereIn('po_id', $poIds)
                ->with('supplier')
                ->withCount(['receivings as events_count' => $applyReceivingFilters])
                ->withMax(['receivings as last_received_date' => $applyReceivingFilters], 'receiving_date')
                ->withSum(['receivings as total_items' => $applyReceivingFilters], 'total_quantity_received');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('po_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q2) use ($search) {
                            $q2->where('supplier_name', 'like', "%{$search}%");
                        });
                });
            }

            $perPage = (int) $request->get('per_page', 20);
            $paginated = $query->orderByDesc('last_received_date')->paginate($perPage);

            $paginated->getCollection()->transform(function ($po) use ($applyReceivingFilters) {
                $whQuery = $po->receivings();
                $applyReceivingFilters($whQuery);
                $po->warehouses = $whQuery->with('location')->get()
                    ->pluck('location.location_name')
                    ->filter()
                    ->unique()
                    ->values();
                return $po;
            });

            return $this->paginated($paginated, 'Receiving log retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve receiving log: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd back-end && php artisan test --filter=ReceivingPoLogTest`
Expected: PASS (5 tests, all green).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ReceivingController.php back-end/routes/api.php back-end/tests/Feature/History/ReceivingPoLogTest.php
git commit -m "feat(api): add PO-grouped receiving history log endpoint"
```

---

### Task 2: Frontend — `poLog` API client method

**Files:**
- Modify: `frontend/src/admin/services/api.js:756-776` (`receivingsAPI` object)

- [ ] **Step 1: Add the `poLog` method**

In `frontend/src/admin/services/api.js`, find:

```js
  /** Per-product progress for a PO: ordered vs scanned, all serial numbers */
  poScanStatus: async (poId) =>
    apiRequest(`/receivings/po-scan-status/${poId}`),
};
```

Replace with:

```js
  /** Per-product progress for a PO: ordered vs scanned, all serial numbers */
  poScanStatus: async (poId) =>
    apiRequest(`/receivings/po-scan-status/${poId}`),

  /** Persistent receiving log, grouped by PO (not one row per scan). */
  poLog: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(`/receivings/po-log${query ? `?${query}` : ''}`);
  },
};
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(api): add receivingsAPI.poLog client method"
```

---

### Task 3: Frontend — extract shared `getStatusConfig` util

**Files:**
- Create: `frontend/src/admin/utils/poStatus.js`
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx:45-54` (remove local def, add import)

- [ ] **Step 1: Create the shared util**

Create `frontend/src/admin/utils/poStatus.js`:

```js
/** Maps a PO/PR status_name to a display label + color key (approved | pending | rejected | partial | completed | cancelled | draft). */
export const getStatusConfig = (statusName) => {
  const s = String(statusName || '').toLowerCase();
  if (s.includes('complet'))                            return { label: 'COMPLETED',          color: 'completed' };
  if (s.includes('partial'))                            return { label: 'PARTIALLY RECEIVED', color: 'partial'   };
  if (s.includes('approv') || s.includes('authoriz'))   return { label: 'APPROVED',           color: 'approved'  };
  if (s.includes('reject'))                             return { label: 'REJECTED',            color: 'rejected'  };
  if (s.includes('cancel'))                             return { label: 'CANCELLED',           color: 'cancelled' };
  if (s.includes('pending'))                            return { label: 'PENDING APPROVAL',    color: 'pending'   };
  return { label: String(statusName || 'DRAFT').toUpperCase(), color: 'draft' };
};
```

- [ ] **Step 2: Update `PurchaseOrderPage.jsx` to import it**

In `frontend/src/admin/pages/PurchaseOrderPage.jsx`, find the import block (lines 27-32):

```js
import { confirmAction, confirmWithReason } from '../utils/confirm';
import { toast } from '../utils/toast';
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
```

Replace with:

```js
import { confirmAction, confirmWithReason } from '../utils/confirm';
import { toast } from '../utils/toast';
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
import { getStatusConfig } from '../utils/poStatus';
```

Then find the local definition (lines 45-54):

```js
const getStatusConfig = (statusName) => {
  const s = String(statusName || '').toLowerCase();
  if (s.includes('complet'))                            return { label: 'COMPLETED',          color: 'completed' };
  if (s.includes('partial'))                            return { label: 'PARTIALLY RECEIVED', color: 'partial'   };
  if (s.includes('approv') || s.includes('authoriz'))   return { label: 'APPROVED',           color: 'approved'  };
  if (s.includes('reject'))                             return { label: 'REJECTED',            color: 'rejected'  };
  if (s.includes('cancel'))                             return { label: 'CANCELLED',           color: 'cancelled' };
  if (s.includes('pending'))                            return { label: 'PENDING APPROVAL',    color: 'pending'   };
  return { label: String(statusName || 'DRAFT').toUpperCase(), color: 'draft' };
};

```

Delete it entirely (the import above now provides `getStatusConfig`).

- [ ] **Step 3: Verify the frontend still builds**

Run: `cd frontend && npm run build`
Expected: build succeeds with no "getStatusConfig is not defined" or duplicate-declaration errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/utils/poStatus.js frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "refactor(ui): extract getStatusConfig to shared util"
```

---

### Task 4: Frontend — `ReceivingHistoryLog` component + styles

**Files:**
- Create: `frontend/src/admin/components/ReceivingHistoryLog.jsx`
- Create: `frontend/src/admin/styles/receiving_history_log.css`

- [ ] **Step 1: Create the stylesheet**

Create `frontend/src/admin/styles/receiving_history_log.css`:

```css
/* Receiving History Log — PO-grouped receiving activity, inside Receive PO's History tab */

.rhl-panel { display: flex; flex-direction: column; gap: 16px; }

.rhl-filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  padding: 14px 20px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.05);
}

.rhl-search {
  flex: 1;
  min-width: 200px;
  padding: 9px 14px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  outline: none;
}

.rhl-select {
  padding: 9px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  background: #fff;
  outline: none;
}

.rhl-date { min-width: 130px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
.rhl-date-sep { color: #6b7280; font-size: 13px; }

.rhl-empty {
  text-align: center;
  padding: 32px 16px;
  color: #6b7280;
  font-size: 14px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
}

.rhl-list { display: flex; flex-direction: column; gap: 10px; }

.rhl-row {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
  text-align: left;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 14px 18px;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.rhl-row:hover { border-color: #9810FA; box-shadow: 0 1px 6px rgba(152,16,250,0.12); }

.rhl-row-main { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.rhl-po-number { font-weight: 700; font-size: 15px; color: #111827; }
.rhl-supplier { font-size: 13px; color: #6b7280; }

.rhl-row-meta {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  font-size: 12.5px;
  color: #4b5563;
}
```

- [ ] **Step 2: Create the component**

Create `frontend/src/admin/components/ReceivingHistoryLog.jsx`:

```jsx
import { useState, useEffect, useCallback } from 'react';
import Loader from './Loader';
import Pagination from './Pagination';
import DateInput from './DateInput';
import { receivingsAPI } from '../services/api';
import '../styles/receiving_history_log.css';

const fmtDate = (s) => {
  if (!s) return '—';
  const [y, m, d] = String(s).slice(0, 10).split('-');
  return m && d ? `${m}/${d}/${y}` : String(s).slice(0, 10);
};

const EMPTY_PAGINATION = { current_page: 1, last_page: 1, per_page: 20, total: 0 };

/**
 * Persistent, PO-grouped log of receiving activity across all purchase orders.
 * Row click opens the shared PO History timeline via onSelectPo(po_id).
 */
export default function ReceivingHistoryLog({ locations = [], onSelectPo }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [locationId, setLocationId] = useState('');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState(EMPTY_PAGINATION);

  const load = useCallback(() => {
    setLoading(true);
    const params = { page, per_page: 20 };
    if (search.trim()) params.search = search.trim();
    if (locationId) params.location_id = locationId;
    if (fromDate) params.from_date = fromDate;
    if (toDate) params.to_date = toDate;
    receivingsAPI.poLog(params)
      .then((res) => {
        setRows(Array.isArray(res?.data) ? res.data : []);
        setPagination(res?.pagination || EMPTY_PAGINATION);
      })
      .catch(() => { setRows([]); setPagination(EMPTY_PAGINATION); })
      .finally(() => setLoading(false));
  }, [page, search, locationId, fromDate, toDate]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { setPage(1); }, [search, locationId, fromDate, toDate]);

  return (
    <div className="rhl-panel">
      <div className="rhl-filters">
        <input
          className="rhl-search"
          placeholder="Search by PO number or supplier..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select className="rhl-select" value={locationId} onChange={(e) => setLocationId(e.target.value)}>
          <option value="">All warehouses</option>
          {locations.map((l) => (
            <option key={l.location_id ?? l.id} value={l.location_id ?? l.id}>
              {l.location_name || l.name}
            </option>
          ))}
        </select>
        <DateInput value={fromDate} onChange={(e) => setFromDate(e.target.value)} className="rhl-date" aria-label="From date" />
        <span className="rhl-date-sep">to</span>
        <DateInput value={toDate} onChange={(e) => setToDate(e.target.value)} className="rhl-date" aria-label="To date" />
      </div>

      {loading ? (
        <Loader label="Loading receiving log" />
      ) : rows.length === 0 ? (
        <div className="rhl-empty">No receiving activity found.</div>
      ) : (
        <div className="rhl-list">
          {rows.map((po) => (
            <button
              type="button"
              key={po.po_id}
              className="rhl-row"
              onClick={() => onSelectPo(po.po_id)}
            >
              <div className="rhl-row-main">
                <span className="rhl-po-number">{po.po_number}</span>
                <span className="rhl-supplier">{po.supplier?.supplier_name || '—'}</span>
              </div>
              <div className="rhl-row-meta">
                <span>{Array.isArray(po.warehouses) && po.warehouses.length ? po.warehouses.join(', ') : '—'}</span>
                <span>{po.events_count} event{po.events_count === 1 ? '' : 's'}</span>
                <span>Last received {fmtDate(po.last_received_date)}</span>
                <span>{po.total_items ?? 0} items</span>
              </div>
            </button>
          ))}
        </div>
      )}

      <Pagination
        currentPage={pagination.current_page}
        totalPages={pagination.last_page}
        totalItems={pagination.total}
        pageSize={pagination.per_page}
        onPageChange={setPage}
        label="purchase orders"
      />
    </div>
  );
}
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/ReceivingHistoryLog.jsx frontend/src/admin/styles/receiving_history_log.css
git commit -m "feat(ui): add ReceivingHistoryLog component"
```

---

### Task 5: Frontend — wire the History tab into `BarcodeScan.jsx`

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeScan.jsx` (imports, state, and the "BSR — PO list view" render block)
- Modify: `frontend/src/admin/styles/po_receiving.css` (append tab-nav styles)

- [ ] **Step 1: Add imports**

In `frontend/src/admin/pages/BarcodeScan.jsx`, find (lines 24-25):

```js
import LocationBadge    from '../components/LocationBadge';
import LocationSelector from '../components/LocationSelector';
import ReceivingReceipt from '../components/ReceivingReceipt';
```

Replace with:

```js
import LocationBadge    from '../components/LocationBadge';
import LocationSelector from '../components/LocationSelector';
import ReceivingReceipt from '../components/ReceivingReceipt';
import RecordHistoryModal from '../components/RecordHistoryModal';
import ReceivingHistoryLog from '../components/ReceivingHistoryLog';
import { getStatusConfig } from '../utils/poStatus';
```

- [ ] **Step 2: Add state and the `openPoHistory` handler**

Find (lines 652-653):

```js
  const [bsrPoReceivedBarcodes, setBsrPoReceivedBarcodes] = useState(new Set());
  const [bsrPoStatusLoading, setBsrPoStatusLoading] = useState(false);
```

Replace with:

```js
  const [bsrPoReceivedBarcodes, setBsrPoReceivedBarcodes] = useState(new Set());
  const [bsrPoStatusLoading, setBsrPoStatusLoading] = useState(false);

  // Receive PO: "Select PO" vs "History" tab, and the shared PO History modal.
  const [bsrTab, setBsrTab] = useState('select'); // 'select' | 'history'
  const [historyRecord, setHistoryRecord] = useState(null);
  const [historyMilestones, setHistoryMilestones] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  const openPoHistory = (poId) => {
    setHistoryLoading(true);
    Promise.all([
      purchaseOrdersAPI.getById(poId),
      purchaseOrdersAPI.history(poId),
    ])
      .then(([poRes, historyRes]) => {
        setHistoryRecord(poRes?.data || null);
        setHistoryMilestones(Array.isArray(historyRes?.data) ? historyRes.data : []);
      })
      .catch(() => { setHistoryRecord(null); setHistoryMilestones([]); })
      .finally(() => setHistoryLoading(false));
  };
```

- [ ] **Step 3: Wire the tab bar and history log into the "BSR — PO list view" render block**

Find this whole block (the "RENDER: BSR — PO list view" section):

```jsx
  /* ─────────────────────────────────────────────
     RENDER: BSR — PO list view
  ───────────────────────────────────────────── */
  if (scanMode?.key === 'receive-po' && !bsrSelectedPo) {
    return (
      <AdminLayout>
        <div className="bsr-page">
          <div className="bsr-header-card">
            <button className="bs-topback-btn" onClick={exitScanMode} style={{ marginRight: 12, flexShrink: 0 }} type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="15 18 9 12 15 6"/></svg>
              Back
            </button>
            <div className="bsr-header-icon"><BsrBarcodeIcon size={26} color="#9810FA" /></div>
            <div className="bsr-header-text">
              <h1>BARCODE SCAN RECEIVE</h1>
              <p>Scan products from approved purchase orders</p>
            </div>
          </div>
          <div className="bsr-search-card">
            <div className="bsr-search-inner">
              <BsrSearchIcon />
              <input
                className="bsr-search-input"
                placeholder="Search by PO ID or supplier..."
                value={bsrPoSearchQ}
                onChange={(e) => setBsrPoSearchQ(e.target.value)}
              />
            </div>
          </div>
          {bsrPoLoading ? (
            <Loader label="Loading purchase orders" />
          ) : bsrFilteredPos.length === 0 ? (
            <div className="bsr-empty">No approved purchase orders found.</div>
          ) : bsrFilteredPos.map((po) => {
            const poId = po.po_id ?? po.id;
            const scoped    = !!myLocationId;
            const totalAmt  = scoped
              ? bsrLocationAmount(po, myLocationId)
              : Number(po.total_amount || po.grand_total || 0);
            const itemCount = scoped ? bsrLocationItemCount(po, myLocationId) : bsrGetItemCount(po);
            const breakdown = scoped ? [] : bsrWarehouseBreakdown(po);
            const showBreakdown = !scoped && breakdown.length > 1;
            const statusName  = po?.status?.status_name ?? po?.status_name ?? '';
            const isPartial   = /partial/i.test(statusName);
            const isCompleted = /complet/i.test(statusName);
            const badgeClass  = isCompleted ? 'bsr-badge--completed' : isPartial ? 'bsr-badge--partial' : 'bsr-badge--approved';
            const badgeLabel  = isCompleted ? 'COMPLETED' : isPartial ? 'PARTIALLY RECEIVED' : statusName.toUpperCase() || 'APPROVED';
            return (
              <button key={poId} type="button" className="bsr-po-card" onClick={() => handleBsrSelectPo(po)}>
                <div className="bsr-po-card-top">
                  <div className="bsr-po-left">
                    <span className="bsr-po-number">{po.po_number || `PO #${poId}`}</span>
                    <span className={`bsr-badge ${badgeClass}`}><BsrCheckIcon /> {badgeLabel}</span>
                  </div>
                  <div className="bsr-po-amount">
                    <span className="bsr-amount-label">TOTAL AMOUNT</span>
                    <span className="bsr-amount-value">{bsrFmtCurrency(totalAmt)}</span>
                    {scoped && (
                      <span className="bsr-amount-scope">for {enforcedLocationName || 'your warehouse'}</span>
                    )}
                  </div>
                </div>
                <div className="bsr-po-card-meta">
                  <div className="bsr-meta-col"><span className="bsr-meta-label">SUPPLIER</span><span className="bsr-meta-value">{bsrGetSupplierName(po)}</span></div>
                  <div className="bsr-meta-col"><span className="bsr-meta-label">APPROVED BY</span><span className="bsr-meta-value">{bsrGetApprovedBy(po)}</span></div>
                  <div className="bsr-meta-col"><span className="bsr-meta-label">EXPECTED DELIVERY</span><span className="bsr-meta-value">{bsrFmtDate(po.expected_delivery_date)}</span></div>
                  <div className="bsr-meta-col">
                    <span className="bsr-meta-label">ITEMS</span>
                    <span className="bsr-meta-value">{typeof itemCount === 'number' ? `${itemCount} items` : '—'}</span>
                  </div>
                </div>
                {showBreakdown && (
                  <div className="bsr-po-breakdown">
                    {breakdown.map((b) => (
                      <span key={b.locationId} className="bsr-breakdown-chip">
                        {b.locationName} <strong>{bsrFmtCurrency(b.amount)}</strong>
                      </span>
                    ))}
                  </div>
                )}
              </button>
            );
          })}
        </div>
      </AdminLayout>
    );
  }
```

Replace with:

```jsx
  /* ─────────────────────────────────────────────
     RENDER: BSR — PO list view
  ───────────────────────────────────────────── */
  if (scanMode?.key === 'receive-po' && !bsrSelectedPo) {
    return (
      <AdminLayout>
        <div className="bsr-page">
          <div className="bsr-header-card">
            <button className="bs-topback-btn" onClick={exitScanMode} style={{ marginRight: 12, flexShrink: 0 }} type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="15 18 9 12 15 6"/></svg>
              Back
            </button>
            <div className="bsr-header-icon"><BsrBarcodeIcon size={26} color="#9810FA" /></div>
            <div className="bsr-header-text">
              <h1>BARCODE SCAN RECEIVE</h1>
              <p>Scan products from approved purchase orders</p>
            </div>
          </div>

          <div className="bsr-tab-nav">
            <button type="button" className={`bsr-tab-btn${bsrTab === 'select' ? ' bsr-tab-btn--active' : ''}`} onClick={() => setBsrTab('select')}>
              Select PO
            </button>
            <button type="button" className={`bsr-tab-btn${bsrTab === 'history' ? ' bsr-tab-btn--active' : ''}`} onClick={() => setBsrTab('history')}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>
              </svg>
              History
            </button>
          </div>

          {bsrTab === 'history' ? (
            <ReceivingHistoryLog locations={locations} onSelectPo={openPoHistory} />
          ) : (
            <>
              <div className="bsr-search-card">
                <div className="bsr-search-inner">
                  <BsrSearchIcon />
                  <input
                    className="bsr-search-input"
                    placeholder="Search by PO ID or supplier..."
                    value={bsrPoSearchQ}
                    onChange={(e) => setBsrPoSearchQ(e.target.value)}
                  />
                </div>
              </div>
              {bsrPoLoading ? (
                <Loader label="Loading purchase orders" />
              ) : bsrFilteredPos.length === 0 ? (
                <div className="bsr-empty">No approved purchase orders found.</div>
              ) : bsrFilteredPos.map((po) => {
                const poId = po.po_id ?? po.id;
                const scoped    = !!myLocationId;
                const totalAmt  = scoped
                  ? bsrLocationAmount(po, myLocationId)
                  : Number(po.total_amount || po.grand_total || 0);
                const itemCount = scoped ? bsrLocationItemCount(po, myLocationId) : bsrGetItemCount(po);
                const breakdown = scoped ? [] : bsrWarehouseBreakdown(po);
                const showBreakdown = !scoped && breakdown.length > 1;
                const statusName  = po?.status?.status_name ?? po?.status_name ?? '';
                const isPartial   = /partial/i.test(statusName);
                const isCompleted = /complet/i.test(statusName);
                const badgeClass  = isCompleted ? 'bsr-badge--completed' : isPartial ? 'bsr-badge--partial' : 'bsr-badge--approved';
                const badgeLabel  = isCompleted ? 'COMPLETED' : isPartial ? 'PARTIALLY RECEIVED' : statusName.toUpperCase() || 'APPROVED';
                return (
                  <button key={poId} type="button" className="bsr-po-card" onClick={() => handleBsrSelectPo(po)}>
                    <div className="bsr-po-card-top">
                      <div className="bsr-po-left">
                        <span className="bsr-po-number">{po.po_number || `PO #${poId}`}</span>
                        <span className={`bsr-badge ${badgeClass}`}><BsrCheckIcon /> {badgeLabel}</span>
                      </div>
                      <div className="bsr-po-amount">
                        <span className="bsr-amount-label">TOTAL AMOUNT</span>
                        <span className="bsr-amount-value">{bsrFmtCurrency(totalAmt)}</span>
                        {scoped && (
                          <span className="bsr-amount-scope">for {enforcedLocationName || 'your warehouse'}</span>
                        )}
                      </div>
                    </div>
                    <div className="bsr-po-card-meta">
                      <div className="bsr-meta-col"><span className="bsr-meta-label">SUPPLIER</span><span className="bsr-meta-value">{bsrGetSupplierName(po)}</span></div>
                      <div className="bsr-meta-col"><span className="bsr-meta-label">APPROVED BY</span><span className="bsr-meta-value">{bsrGetApprovedBy(po)}</span></div>
                      <div className="bsr-meta-col"><span className="bsr-meta-label">EXPECTED DELIVERY</span><span className="bsr-meta-value">{bsrFmtDate(po.expected_delivery_date)}</span></div>
                      <div className="bsr-meta-col">
                        <span className="bsr-meta-label">ITEMS</span>
                        <span className="bsr-meta-value">{typeof itemCount === 'number' ? `${itemCount} items` : '—'}</span>
                      </div>
                    </div>
                    {showBreakdown && (
                      <div className="bsr-po-breakdown">
                        {breakdown.map((b) => (
                          <span key={b.locationId} className="bsr-breakdown-chip">
                            {b.locationName} <strong>{bsrFmtCurrency(b.amount)}</strong>
                          </span>
                        ))}
                      </div>
                    )}
                  </button>
                );
              })}
            </>
          )}

          <RecordHistoryModal
            isOpen={!!historyRecord}
            onClose={() => setHistoryRecord(null)}
            kind="Purchase Order"
            record={historyRecord}
            statusLabel={historyRecord ? getStatusConfig(historyRecord.status?.status_name).label : ''}
            statusColor={historyRecord ? getStatusConfig(historyRecord.status?.status_name).color : 'draft'}
            milestones={historyMilestones}
            loading={historyLoading}
          />
        </div>
      </AdminLayout>
    );
  }
```

- [ ] **Step 4: Append tab styles**

Append to the end of `frontend/src/admin/styles/po_receiving.css`:

```css

/* ── Receive PO: Select PO / History tab switch ─────────────── */
.bsr-tab-nav {
  display: flex;
  gap: 8px;
  border-bottom: 1px solid #e5e7eb;
  padding-bottom: 0;
}
.bsr-tab-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  font-size: 14px;
  font-weight: 600;
  color: #6b7280;
  cursor: pointer;
}
.bsr-tab-btn:hover { color: #374151; }
.bsr-tab-btn--active {
  color: #9810FA;
  border-bottom-color: #9810FA;
}
```

- [ ] **Step 5: Build and manually verify**

Run: `cd frontend && npm run build`
Expected: build succeeds with no errors.

Then run the dev server (`npm start` or `npm run demo` per this project's convention — see `[[project_perf_dev_vs_prod]]`), log in, open **Inventory Operation → Receive PO**, and confirm:
- The "Select PO" / "History" tabs render above the search bar.
- Switching to "History" shows the receiving log (or an empty state if there's no receiving data yet), with working search/warehouse/date filters and pagination.
- Clicking a row opens the same PO History modal (timeline + line items) that already works from the Purchase Orders page.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/BarcodeScan.jsx frontend/src/admin/styles/po_receiving.css
git commit -m "feat(ui): wire Receiving History Log into Receive PO screen"
```

---

## Self-Review Notes

- **Spec coverage:** Backend endpoint + grouping (Task 1), frontend API client (Task 2), row click → existing `RecordHistoryModal` via shared `getStatusConfig` (Tasks 3 & 5), filter bar + pagination (Task 4), tab placement inside `BarcodeScan.jsx`'s Select-PO screen (Task 5) — all spec sections are covered.
- **No placeholders:** every step has complete, runnable code — no TBD/TODO.
- **Type/name consistency checked:** `receivingsAPI.poLog`, `ReceivingHistoryLog` props (`locations`, `onSelectPo`), `openPoHistory(poId)`, and `getStatusConfig` are named identically everywhere they're used across Tasks 2–5.
