# Role Dashboards Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the four non-admin role dashboards show real, location-scoped data and rebuild them on the `ak-` design system.

**Architecture:** Harden the single `DashboardController::index()` into a role-aware, location-scoped payload (scoping enforced server-side). Fix the broken batch invocation. Rebuild each dashboard (`BranchManager`, `WarehousePersonnel`, `InventoryAnalyst`, `Auditor`) on a shared frontend "dashboard kit" (data hook + KPI card + chart presets) using `ak-` primitives. Admin `Dashboard.jsx` is untouched.

**Tech Stack:** Laravel (PHP) backend, React (CRA) + Chart.js (`react-chartjs-2`) frontend, the project's `airking_ds.css` (`ak-*`) design system.

**Spec:** `docs/superpowers/specs/2026-06-22-role-dashboards-redesign-design.md`

---

## File Structure

- Modify: `back-end/app/Http/Controllers/Api/DashboardController.php` — thread `$user`/`$locId` through `index()` and every sub-builder; scope queries for non-centralized users.
- Modify: `back-end/app/Http/Controllers/Api/BatchController.php:189` — pass the request into `index()`.
- Create: `back-end/tests/Feature/Dashboard/DashboardScopeTest.php` — proves scoped vs centralized payload.
- Create: `frontend/src/admin/pages/dashboard/dashboardKit.jsx` — shared `useRoleDashboard` hook, `Kpi`, chart presets, formatters.
- Modify: `frontend/src/admin/pages/BranchManagerDashboard.jsx` — full rebuild.
- Modify: `frontend/src/admin/pages/WarehousePersonnelDashboard.jsx` — full rebuild.
- Modify: `frontend/src/admin/pages/InventoryAnalystDashboard.jsx` — full rebuild.
- Modify: `frontend/src/admin/pages/AuditorDashboard.jsx` — full rebuild.

Data contract (payload keys from `index()`, all scoped after Task 1):
`stats` (`low_stock`, `pending_pos`, `pending_transfers`, `pending_registrations`, `pending_actions`, `total_sales`, `awaiting_fulfillment`, `awaiting_receipt`, `active_locations`), `low_stock_items[]` (`product_name`, `category`, `brand`, `quantity`, `recommended_stocks`, `deficiency`), `inventory_by_category` (`labels`,`values`), `location_distribution` (`labels`,`values`), `stock_status_by_location` (`labels`,`inStock`,`lowStock`), `warehouse_capacity` (`labels`,`capacity`,`occupancy`), `stock_trends` (`labels`,`values`,`reorder_level`), `transaction_trends` (`labels`,`values`,`purchase_values`), `recent_activity[]` (`type`,`label`,`description`,`location`,`user`,`status`,`timestamp`).

Location KPIs that the payload does not provide per-location (SKU count, stock units, out-of-stock) are derived on the frontend from the inventory list, which scoped dashboards request with an explicit `location_id`.

---

## Task 1: Scope the dashboard payload by location

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/DashboardController.php`
- Modify: `back-end/app/Http/Controllers/Api/BatchController.php`
- Test: `back-end/tests/Feature/Dashboard/DashboardScopeTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Dashboard/DashboardScopeTest.php`:

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\StatusLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function activeUserStatusId(): int
    {
        return (int) (StatusLookup::where('status_category', 'user')->where('status_name', 'Active')->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id);
    }

    public function test_scoped_user_location_distribution_only_contains_their_location(): void
    {
        $statusId = $this->activeUserStatusId();

        $locA = Location::forceCreate(['location_code' => 'DSH_A', 'location_name' => 'Dash A', 'location_type' => 'branch_office', 'status_id' => 1]);
        $locB = Location::forceCreate(['location_code' => 'DSH_B', 'location_name' => 'Dash B', 'location_type' => 'warehouse', 'status_id' => 1]);

        // branch_manager = role 3 (scoped)
        $bm = User::forceCreate([
            'first_name' => 'Dash', 'last_name' => 'BM',
            'username' => 'dash_bm_' . uniqid(), 'email' => 'dash_bm_' . uniqid() . '@t.test',
            'password_hash' => Hash::make('x'), 'role_id' => 3, 'status_id' => $statusId,
            'location_id' => $locA->location_id,
        ]);

        $res = $this->actingAs($bm)->getJson('/api/dashboard');
        $res->assertOk();

        $labels = $res->json('location_distribution.labels');
        $this->assertEquals(['Dash A'], $labels, 'scoped user must only see their own location');
    }

    public function test_centralized_user_sees_all_locations(): void
    {
        $admin = User::where('role_id', 1)->first();
        $this->assertNotNull($admin, 'an admin user must exist in the test DB');

        $res = $this->actingAs($admin)->getJson('/api/dashboard');
        $res->assertOk();

        $labels = $res->json('location_distribution.labels');
        $this->assertContains('Dash A', $labels === null ? [] : $labels, 'centralized user should see seeded/other locations');
        $this->assertGreaterThan(1, count($labels ?? []), 'centralized payload should include multiple locations');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd back-end && php artisan test --filter=DashboardScopeTest`
Expected: `test_scoped_user_location_distribution_only_contains_their_location` FAILS — currently `getLocationDistribution()` ignores the user and returns all locations (and the batch/index path is unscoped). (The centralized test may already pass.)

- [ ] **Step 3: Fix the BatchController invocation**

In `back-end/app/Http/Controllers/Api/BatchController.php`, change the dashboard include (around line 189) from:

```php
$dashboard = app(DashboardController::class)->index();
```

to:

```php
$dashboard = app(DashboardController::class)->index(request());
```

- [ ] **Step 4: Thread `$user`/`$locId` through `index()` and the sub-builders**

In `back-end/app/Http/Controllers/Api/DashboardController.php`, replace the body of `index()` (lines 24-37) with:

```php
    public function index(Request $request)
    {
        $user          = $request->user();
        $isCentralized = $user === null || $user->isCentralized();
        $locId         = $isCentralized ? null : $user->scopedLocationId();

        return response()->json([
            'stats'                    => $this->getStats($user),
            'low_stock_items'          => $this->getLowStockItems($locId),
            'inventory_by_category'    => $this->buildInventoryByCategory(null, null, null, $locId),
            'location_distribution'    => $this->getLocationDistribution($locId),
            'stock_status_by_location' => $this->buildStockByLocation(null, null, null, $locId),
            'warehouse_capacity'       => $this->getWarehouseCapacity($locId),
            'stock_trends'             => $this->buildStockTrends(now()->subWeeks(3)->toDateString(), now()->toDateString(), null, null, null, $locId),
            'transaction_trends'       => $this->buildTransactionTrends(now()->subDays(6)->toDateString(), now()->toDateString(), $locId),
            'recent_activity'          => $this->getRecentActivity($locId),
        ]);
    }
```

Then apply the scoping in each builder (a scoped `$locId` is a non-null int; centralized is `null` = no restriction):

`getLowStockItems(?int $locId = null)` — change the correlated subquery so the inner SUM restricts to the location when scoped. Replace the method body's `$sub` with:

```php
        $sub = $locId === null
            ? '(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id)'
            : '(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id AND inventory.location_id = ' . (int) $locId . ')';
```

(The rest of the method is unchanged; `(int) $locId` is safe — it is derived from `scopedLocationId()`, never user input.)

`buildInventoryByCategory(?int $catId, ?int $brandId, ?int $productId, ?int $locId = null)` — after the existing joins/filters and before `selectRaw`, add:

```php
        if ($locId !== null) $q->where('inventory.location_id', $locId);
```

`getLocationDistribution(?int $locId = null)` — restrict the location set:

```php
        $locations = Location::withCount('inventories')
            ->when($locId !== null, fn ($q) => $q->where('location_id', $locId))
            ->get();
```

(Leave the empty-fallback block intact; it only triggers when there are zero locations.)

`buildStockByLocation(?int $catId, ?int $brandId, ?int $productId, ?int $locId = null)` — restrict the locations loop:

```php
        $locations = Location::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->get();
```

`getWarehouseCapacity(?int $locId = null)` — restrict the locations loop:

```php
        $locations = Location::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->get();
```

`buildStockTrends(string $dateFrom, string $dateTo, ?int $catId, ?int $brandId, ?int $productId, ?int $locId = null)` — after each `DB::table('inventory as inv')...` query and its `applyInventoryFilters` call, add the location filter. In the weekly loop add after the `applyInventoryFilters($q, ...)` line:

```php
            if ($locId !== null) $q->where('inv.location_id', $locId);
```

and after the reorder query's `applyInventoryFilters($rq, ...)`:

```php
        if ($locId !== null) $rq->where('inv.location_id', $locId);
```

`buildTransactionTrends(string $dateFrom, string $dateTo, ?int $locId = null)` — scope Sale and PurchaseOrder counts. Replace the two count expressions in BOTH branches so they read e.g.:

```php
                $saleValues[]     = Sale::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->whereDate('created_at', $ds)->count();
                $purchaseValues[] = PurchaseOrder::when($locId !== null, fn ($q) => $q->where('location_id', $locId))->whereDate('created_at', $ds)->count();
```

(and the analogous `whereYear/whereMonth` branch).

`getRecentActivity(?int $locId = null)` — scope each source. Add `->when($locId !== null, ...)` to the Sale, Transfer, and Receiving queries:

```php
        // Sale (has location_id):
        foreach (Sale::with(['customer', 'location', 'soldBy'])
            ->when($locId !== null, fn ($q) => $q->where('location_id', $locId))
            ->orderBy('created_at', 'desc')->limit(5)->get() as $s) { ... }

        // Transfer (from_location_id / to_location_id):
        foreach (Transfer::with(['fromLocation', 'toLocation', 'requestedBy', 'status'])
            ->when($locId !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_location_id', $locId)->orWhere('to_location_id', $locId)))
            ->orderBy('created_at', 'desc')->limit(5)->get() as $t) { ... }

        // Receiving (has location_id):
        foreach (Receiving::with(['supplier', 'location', 'receivedBy'])
            ->when($locId !== null, fn ($q) => $q->where('location_id', $locId))
            ->orderBy('created_at', 'desc')->limit(5)->get() as $r) { ... }
```

(Keep each existing `try { ... } catch` wrapper and row-mapping unchanged — only add the `->when(...)` clause to each query.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd back-end && php artisan test --filter=DashboardScopeTest`
Expected: PASS (both tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/DashboardController.php back-end/app/Http/Controllers/Api/BatchController.php back-end/tests/Feature/Dashboard/DashboardScopeTest.php
git commit -m "feat(dashboard): scope dashboard payload to user location; fix batch invocation

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Shared frontend dashboard kit

**Files:**
- Create: `frontend/src/admin/pages/dashboard/dashboardKit.jsx`

- [ ] **Step 1: Create the kit**

```jsx
import React, { useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import { batchAPI, inventoryAPI } from '../../services/api';

export const PALETTE = {
  crimson: '#dc143c', crimsonSoft: 'rgba(220,20,60,0.12)',
  gold: '#f5b301', green: '#059669', greenSoft: 'rgba(5,150,105,0.12)',
  blue: '#2563eb', amber: '#d97706', gray: '#9ca3af', grid: '#eef1f7', ink: '#5b6472',
};

export const fmt = (n) => Number(n || 0).toLocaleString();

/** Centralized roles (admin=1, inventory_analyst=2, auditor=5) see all locations. */
export const isCentralizedRole = (user) => [1, 2, 5].includes(Number(user?.role?.role_id));

/**
 * Fetches the (server-scoped) dashboard payload plus an inventory list.
 * Scoped roles request inventory for their own location only; centralized
 * roles request unfiltered (capped) inventory.
 */
export function useRoleDashboard() {
  const { user: _user } = useAuth();
  const user = _user || {};
  const centralized = isCentralizedRole(user);
  const locationId = centralized ? null : (user?.location?.location_id ?? null);

  const [data, setData] = useState(null);
  const [inv, setInv] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const invParams = { per_page: 300, ...(locationId ? { location_id: locationId } : {}) };
        const [dashRes, invRes] = await Promise.allSettled([
          batchAPI.get({ include: ['dashboard'] }),
          inventoryAPI.getAll(invParams),
        ]);
        if (!alive) return;
        if (dashRes.status === 'fulfilled') {
          const d = dashRes.value?.data?.dashboard ?? null;
          setData(d && d.success === false ? null : d);
        }
        if (invRes.status === 'fulfilled') {
          const items = invRes.value?.data ?? invRes.value?.inventory ?? [];
          setInv(Array.isArray(items) ? items : []);
        }
      } catch (e) {
        if (alive) setError(e.message || 'Failed to load dashboard.');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => { alive = false; };
  }, [locationId]);

  return { user, centralized, locationName: user?.location?.location_name || user?.branch?.branch_name || 'All Locations', data, inv, loading, error };
}

/** A single KPI stat card using the ak- design system. */
export function Kpi({ label, value, icon, tone = 'crimson' }) {
  return (
    <div className="ak-stat">
      <div className="ak-stat-icon" style={{ color: PALETTE[tone] || PALETTE.crimson, background: 'var(--ak-band)' }}>
        {icon}
      </div>
      <div>
        <div className="ak-stat-label">{label}</div>
        <div className="ak-stat-value">{value}</div>
      </div>
    </div>
  );
}

export const baseChartOptions = {
  responsive: true, maintainAspectRatio: false,
  plugins: { legend: { display: false }, tooltip: { backgroundColor: '#14181f', padding: 10, cornerRadius: 8 } },
  scales: {
    x: { grid: { display: false }, ticks: { color: PALETTE.ink, font: { size: 11 } } },
    y: { grid: { color: PALETTE.grid }, ticks: { color: PALETTE.ink, font: { size: 11 } }, beginAtZero: true },
  },
};
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/admin/pages/dashboard/dashboardKit.jsx
git commit -m "feat(dashboard): shared role-dashboard kit (hook, KPI card, chart presets)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Rebuild BranchManagerDashboard (scoped)

**Files:**
- Modify: `frontend/src/admin/pages/BranchManagerDashboard.jsx` (full replace of the component body/return)

KPIs (their location): SKUs on hand (distinct products with qty>0 in `inv`), Low-stock (`stats.low_stock`), Pending approvals (`stats.pending_pos + stats.pending_transfers`), Stock units (sum of `inv` qty). Chart: stock movement trend (`transaction_trends` sales vs purchases, line). Lists: low-stock items (`low_stock_items`); pending approvals summary card.

- [ ] **Step 1: Replace the file contents**

```jsx
import React from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { Line } from 'react-chartjs-2';
import {
  Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler,
} from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler);

const qtyOf = (i) => Number(i.quantity_on_hand ?? i.quantity ?? 0);

export default function BranchManagerDashboard() {
  const navigate = useNavigate();
  const { locationName, data, inv, loading, error } = useRoleDashboard();

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: PALETTE.green }} /><p>Loading branch data…</p></div></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const skus = inv.filter((i) => qtyOf(i) > 0).length;
  const units = inv.reduce((s, i) => s + qtyOf(i), 0);
  const pendingApprovals = Number(stats.pending_pos || 0) + Number(stats.pending_transfers || 0);
  const lowStockItems = data?.low_stock_items ?? [];
  const tt = data?.transaction_trends ?? { labels: [], values: [], purchase_values: [] };

  const trendData = {
    labels: tt.labels,
    datasets: [
      { label: 'Sales out', data: tt.values, borderColor: PALETTE.crimson, backgroundColor: PALETTE.crimsonSoft, fill: true, tension: 0.35, pointRadius: 2 },
      { label: 'Purchases in', data: tt.purchase_values, borderColor: PALETTE.green, backgroundColor: PALETTE.greenSoft, fill: true, tension: 0.35, pointRadius: 2 },
    ],
  };
  const trendOptions = { ...baseChartOptions, plugins: { ...baseChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, color: PALETTE.ink, font: { size: 11 } } } } };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Branch Operations · {locationName}</p>
              <h1 className="ak-title">Branch Dashboard</h1>
              <p className="ak-sub">Live stock, demand and approvals for your location.</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/inventory')}>View inventory</button>
              <button className="ak-btn ak-btn--primary" onClick={() => navigate('/admin/approvals')}>Approvals</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="SKUs on hand" value={fmt(skus)} tone="green" icon={<Box />} />
          <Kpi label="Low stock" value={fmt(stats.low_stock)} tone="amber" icon={<Alert />} />
          <Kpi label="Pending approvals" value={fmt(pendingApprovals)} tone="crimson" icon={<Check />} />
          <Kpi label="Stock units" value={fmt(units)} tone="blue" icon={<Layers />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Stock movement (last 7 days)</h2><p className="ak-card-meta">Sales out vs purchases in</p></div></div>
          <div style={{ height: 280 }}>
            {tt.labels?.length ? <Line data={trendData} options={trendOptions} /> : <div className="ak-state">No movement recorded in this period.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Low-stock items</h2><p className="ak-card-meta">Below recommended level at {locationName}</p></div></div>
          {lowStockItems.length === 0 ? (
            <div className="ak-state">No low-stock items — your branch is well stocked. 🎉</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Product</th><th>Category</th><th>On hand</th><th>Recommended</th><th>Short by</th></tr></thead>
                <tbody>
                  {lowStockItems.map((it, i) => (
                    <tr key={i}>
                      <td>{it.product_name}</td>
                      <td className="ak-muted">{it.category || '—'}</td>
                      <td>{fmt(it.quantity)}</td>
                      <td>{fmt(it.recommended_stocks)}</td>
                      <td><span className="ak-badge ak-badge--red">{fmt(it.deficiency)}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </AdminLayout>
  );
}

const Box = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>);
const Alert = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>);
const Check = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>);
const Layers = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>);
```

- [ ] **Step 2: Build to verify it compiles**

Run: `cd frontend && CI=false node_modules/.bin/react-scripts build 2>&1 | grep -iE "compiled|failed"`
Expected: `Compiled` (warnings about html5-qrcode source maps are pre-existing and OK). Then `rm -rf build`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/BranchManagerDashboard.jsx
git commit -m "feat(dashboard): rebuild Branch Manager dashboard on ak- system with scoped data

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Rebuild WarehousePersonnelDashboard (scoped)

**Files:**
- Modify: `frontend/src/admin/pages/WarehousePersonnelDashboard.jsx` (full replace)

KPIs (their location): SKUs at warehouse, Items awaiting receipt (`stats.awaiting_receipt`), To dispatch (`stats.awaiting_fulfillment`), Low-stock (`stats.low_stock`). Chart: inventory by category (`inventory_by_category`, bar). List: low-stock items (`low_stock_items`).

- [ ] **Step 1: Replace the file contents**

```jsx
import React from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip, Legend } from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const qtyOf = (i) => Number(i.quantity_on_hand ?? i.quantity ?? 0);

export default function WarehousePersonnelDashboard() {
  const navigate = useNavigate();
  const { locationName, data, inv, loading, error } = useRoleDashboard();

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: PALETTE.blue }} /><p>Loading warehouse data…</p></div></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const skus = inv.filter((i) => qtyOf(i) > 0).length;
  const cat = data?.inventory_by_category ?? { labels: [], values: [] };
  const lowStockItems = data?.low_stock_items ?? [];

  const catData = { labels: cat.labels, datasets: [{ label: 'Units', data: cat.values, backgroundColor: PALETTE.blue, borderRadius: 6, maxBarThickness: 46 }] };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Warehouse · {locationName}</p>
              <h1 className="ak-title">Warehouse Dashboard</h1>
              <p className="ak-sub">Receiving, dispatch and stock health for your warehouse.</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/ist')}>Transfers</button>
              <button className="ak-btn ak-btn--primary" onClick={() => navigate('/admin/inventory-operation')}>Scan</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="SKUs at warehouse" value={fmt(skus)} tone="blue" icon={<Box />} />
          <Kpi label="Awaiting receipt" value={fmt(stats.awaiting_receipt)} tone="amber" icon={<In />} />
          <Kpi label="To dispatch" value={fmt(stats.awaiting_fulfillment)} tone="crimson" icon={<Out />} />
          <Kpi label="Low stock" value={fmt(stats.low_stock)} tone="green" icon={<Alert />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Inventory by category</h2><p className="ak-card-meta">Units on hand at {locationName}</p></div></div>
          <div style={{ height: 280 }}>
            {cat.labels?.length ? <Bar data={catData} options={baseChartOptions} /> : <div className="ak-state">No inventory recorded yet.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Low-stock items</h2><p className="ak-card-meta">Need restocking</p></div></div>
          {lowStockItems.length === 0 ? (
            <div className="ak-state">Nothing low on stock right now.</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Product</th><th>Category</th><th>On hand</th><th>Short by</th></tr></thead>
                <tbody>
                  {lowStockItems.map((it, i) => (
                    <tr key={i}>
                      <td>{it.product_name}</td>
                      <td className="ak-muted">{it.category || '—'}</td>
                      <td>{fmt(it.quantity)}</td>
                      <td><span className="ak-badge ak-badge--red">{fmt(it.deficiency)}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </AdminLayout>
  );
}

const Box = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>);
const In = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>);
const Out = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>);
const Alert = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>);
```

- [ ] **Step 2: Build to verify** — `cd frontend && CI=false node_modules/.bin/react-scripts build 2>&1 | grep -iE "compiled|failed"` → `Compiled`; then `rm -rf build`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/WarehousePersonnelDashboard.jsx
git commit -m "feat(dashboard): rebuild Warehouse Personnel dashboard on ak- system with scoped data

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Rebuild InventoryAnalystDashboard (centralized)

**Files:**
- Modify: `frontend/src/admin/pages/InventoryAnalystDashboard.jsx` (full replace)

KPIs (global): Total SKUs (distinct products with qty>0 across `inv`), Low-stock (`stats.low_stock`), Out-of-stock (products with 0 across `inv` — derive from `inv`), Stock units. Charts: inventory by category (bar); stock status by location (stacked healthy/low from `stock_status_by_location`). List: top low-stock items (`low_stock_items`).

- [ ] **Step 1: Replace the file contents**

```jsx
import React from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip, Legend } from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const qtyOf = (i) => Number(i.quantity_on_hand ?? i.quantity ?? 0);

export default function InventoryAnalystDashboard() {
  const navigate = useNavigate();
  const { data, inv, loading, error } = useRoleDashboard();

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: PALETTE.crimson }} /><p>Loading analytics…</p></div></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const totalSkus = inv.length;
  const outOfStock = inv.filter((i) => qtyOf(i) <= 0).length;
  const units = inv.reduce((s, i) => s + qtyOf(i), 0);
  const cat = data?.inventory_by_category ?? { labels: [], values: [] };
  const byLoc = data?.stock_status_by_location ?? { labels: [], inStock: [], lowStock: [] };
  const lowStockItems = data?.low_stock_items ?? [];

  const catData = { labels: cat.labels, datasets: [{ label: 'Units', data: cat.values, backgroundColor: PALETTE.crimson, borderRadius: 6, maxBarThickness: 42 }] };
  const locData = {
    labels: byLoc.labels,
    datasets: [
      { label: 'Healthy', data: byLoc.inStock, backgroundColor: PALETTE.green, borderRadius: 4, stack: 's' },
      { label: 'Low', data: byLoc.lowStock, backgroundColor: PALETTE.gold, borderRadius: 4, stack: 's' },
    ],
  };
  const stackedOptions = {
    ...baseChartOptions,
    plugins: { ...baseChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, color: PALETTE.ink, font: { size: 11 } } } },
    scales: { x: { ...baseChartOptions.scales.x, stacked: true }, y: { ...baseChartOptions.scales.y, stacked: true } },
  };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory Analytics · All Locations</p>
              <h1 className="ak-title">Inventory Analyst Dashboard</h1>
              <p className="ak-sub">Network-wide stock health, category mix and shortfalls.</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/po-recommendations')}>Reorder advice</button>
              <button className="ak-btn ak-btn--primary" onClick={() => navigate('/admin/inventory')}>Inventory</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="Total SKUs" value={fmt(totalSkus)} tone="crimson" icon={<Box />} />
          <Kpi label="Low stock" value={fmt(stats.low_stock)} tone="amber" icon={<Alert />} />
          <Kpi label="Out of stock" value={fmt(outOfStock)} tone="crimson" icon={<Zero />} />
          <Kpi label="Stock units" value={fmt(units)} tone="green" icon={<Layers />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Stock status by location</h2><p className="ak-card-meta">Healthy vs low SKUs per location</p></div></div>
          <div style={{ height: 300 }}>
            {byLoc.labels?.length ? <Bar data={locData} options={stackedOptions} /> : <div className="ak-state">No location data.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Inventory by category</h2><p className="ak-card-meta">Units on hand network-wide</p></div></div>
          <div style={{ height: 280 }}>
            {cat.labels?.length ? <Bar data={catData} options={baseChartOptions} /> : <div className="ak-state">No inventory recorded yet.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Top low-stock items</h2><p className="ak-card-meta">Largest shortfalls across the network</p></div></div>
          {lowStockItems.length === 0 ? (
            <div className="ak-state">No low-stock items network-wide.</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Product</th><th>Category</th><th>Brand</th><th>On hand</th><th>Short by</th></tr></thead>
                <tbody>
                  {lowStockItems.map((it, i) => (
                    <tr key={i}>
                      <td>{it.product_name}</td>
                      <td className="ak-muted">{it.category || '—'}</td>
                      <td className="ak-muted">{it.brand || '—'}</td>
                      <td>{fmt(it.quantity)}</td>
                      <td><span className="ak-badge ak-badge--red">{fmt(it.deficiency)}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </AdminLayout>
  );
}

const Box = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>);
const Alert = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>);
const Zero = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="4.9" y1="4.9" x2="19.1" y2="19.1"/></svg>);
const Layers = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>);
```

- [ ] **Step 2: Build to verify** — `cd frontend && CI=false node_modules/.bin/react-scripts build 2>&1 | grep -iE "compiled|failed"` → `Compiled`; then `rm -rf build`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/InventoryAnalystDashboard.jsx
git commit -m "feat(dashboard): rebuild Inventory Analyst dashboard on ak- system with real data

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Rebuild AuditorDashboard (centralized, read-only)

**Files:**
- Modify: `frontend/src/admin/pages/AuditorDashboard.jsx` (full replace)

KPIs (global): Transactions 30d (sum of `transaction_trends.values` + `purchase_values`), Pending approvals (`stats.pending_actions`), Pending registrations (`stats.pending_registrations`), Active locations (`stats.active_locations`). Chart: activity trend (`transaction_trends`). List: recent audit feed (`recent_activity`) with action badges.

- [ ] **Step 1: Replace the file contents**

```jsx
import React from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { Line } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler } from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler);

const TYPE_TONE = { sale: 'green', transfer: 'blue', receiving: 'amber', adjustment: 'violet', default: 'gray' };

export default function AuditorDashboard() {
  const navigate = useNavigate();
  const { data, loading, error } = useRoleDashboard();

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: PALETTE.amber }} /><p>Loading audit data…</p></div></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const tt = data?.transaction_trends ?? { labels: [], values: [], purchase_values: [] };
  const txn30 = (tt.values || []).reduce((a, b) => a + Number(b || 0), 0) + (tt.purchase_values || []).reduce((a, b) => a + Number(b || 0), 0);
  const activity = data?.recent_activity ?? [];

  const trendData = {
    labels: tt.labels,
    datasets: [{ label: 'Transactions', data: (tt.values || []).map((v, i) => Number(v || 0) + Number((tt.purchase_values || [])[i] || 0)), borderColor: PALETTE.amber, backgroundColor: 'rgba(217,119,6,0.12)', fill: true, tension: 0.35, pointRadius: 2 }],
  };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Audit &amp; Oversight · All Locations</p>
              <h1 className="ak-title">Auditor Dashboard</h1>
              <p className="ak-sub">System-wide activity and control checkpoints (read-only).</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/audit-trail')}>Audit trail</button>
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/compliance')}>Compliance</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="Transactions (30d)" value={fmt(txn30)} tone="amber" icon={<Activity />} />
          <Kpi label="Pending approvals" value={fmt(stats.pending_actions)} tone="crimson" icon={<Clock />} />
          <Kpi label="Pending registrations" value={fmt(stats.pending_registrations)} tone="blue" icon={<Tag />} />
          <Kpi label="Active locations" value={fmt(stats.active_locations)} tone="green" icon={<Pin />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Activity trend</h2><p className="ak-card-meta">Transactions over the last 7 days</p></div></div>
          <div style={{ height: 280 }}>
            {tt.labels?.length ? <Line data={trendData} options={baseChartOptions} /> : <div className="ak-state">No activity in this period.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Recent activity</h2><p className="ak-card-meta">Latest sales, transfers and receivings</p></div></div>
          {activity.length === 0 ? (
            <div className="ak-state">No recent activity recorded.</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Type</th><th>Event</th><th>Detail</th><th>By</th><th>Status</th></tr></thead>
                <tbody>
                  {activity.map((a, i) => (
                    <tr key={i}>
                      <td><span className={`ak-badge ak-badge--${TYPE_TONE[a.type] || TYPE_TONE.default}`}>{a.type}</span></td>
                      <td>{a.label}</td>
                      <td className="ak-muted">{a.description}</td>
                      <td className="ak-muted">{a.user || '—'}</td>
                      <td className="ak-muted">{a.status || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </AdminLayout>
  );
}

const Activity = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>);
const Clock = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>);
const Tag = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>);
const Pin = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>);
```

- [ ] **Step 2: Build to verify** — `cd frontend && CI=false node_modules/.bin/react-scripts build 2>&1 | grep -iE "compiled|failed"` → `Compiled`; then `rm -rf build`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/AuditorDashboard.jsx
git commit -m "feat(dashboard): rebuild Auditor dashboard on ak- system with real audit feed

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Final verification

- [ ] **Step 1: Backend tests green**

Run: `cd back-end && php artisan test --filter=DashboardScopeTest`
Expected: PASS.

- [ ] **Step 2: Frontend builds clean**

Run: `cd frontend && CI=false node_modules/.bin/react-scripts build 2>&1 | tail -5`
Expected: "The build folder is ready to be deployed." Then `rm -rf build`.

- [ ] **Step 3: Manual smoke check (record results, do not auto-pass)**

Log in as each non-admin role and confirm: (a) KPIs show non-zero where data exists, (b) Branch Manager / Warehouse Personnel see only their own location's numbers and low-stock list, (c) Inventory Analyst / Auditor see network-wide data, (d) empty panels render an `ak-state` message, not a blank. Note any gaps before claiming done.

- [ ] **Step 4: Update memory**

Append to `project_design_system.md` / create a dashboards note: dashboard payload is now role-scoped in `DashboardController::index()`; batch path fixed; the four non-admin dashboards use `dashboard/dashboardKit.jsx` + `ak-` system; Admin dashboard intentionally untouched.

---

## Notes / Risks

- `index()` returning a superset payload means scoped charts like `location_distribution` collapse to a single bar for BM/WP — that's expected and acceptable.
- If `inventoryAPI.getAll` ignores `location_id` server-side for some roles, the scoped SKU/units KPIs could over-count. Mitigation: the authoritative scoped numbers (`low_stock`, pending, awaiting) come from the server-scoped `stats`; the inventory-derived KPIs are secondary. If over-counting is observed in the smoke check, add server-side scoping to `InventoryController::index()` as a follow-up (out of scope here).
- Charts depend on `react-chartjs-2` + `chart.js`, already used by the current dashboards — no new dependency.
