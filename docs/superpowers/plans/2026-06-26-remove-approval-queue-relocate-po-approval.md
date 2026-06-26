# Remove Approval Queue, Relocate PO Approval — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the redundant Approval Queue page and relocate purchase-order approve/reject into the existing Purchase Orders page (`PurchaseOrderPage`, PO Status tab).

**Architecture:** PR and Transfer approvals already live in their own pages; the Approval Queue's only unique function is PO approval. We add PO approve/reject (card + detail modal, permission-gated) to `PurchaseOrderPage`, then remove the Approval Queue page, its routes (redirected to the Purchase Orders page), its sidebar item, and its nav-bundle entries. The pending-approval badge moves to the Purchase Orders nav item. No backend controller/permission changes — approval continues through the existing `purchaseOrdersAPI.update` path.

**Tech Stack:** React 19 (CRA / react-scripts), react-router-dom v7, Jest + React Testing Library, Laravel (PHP) backend with a DB-seeded nav-bundle table.

**Spec:** `docs/superpowers/specs/2026-06-26-remove-approval-queue-relocate-po-approval-design.md`

---

## File Structure

**Modify:**
- `frontend/src/admin/pages/PurchaseOrderPage.jsx` — add PO approve/reject logic + UI (card + modal), gated on `purchase_order_creation.update`.
- `frontend/src/admin/utils/navBundles.js` — remove the `Approval Queue` bundle entry.
- `back-end/database/seeders/NavBundleSeeder.php` — remove the `Approval Queue` row.
- `frontend/src/admin/components/Sidebar.jsx` — remove the Approval Queue menu item; move the `pendingApprovalCount` badge to the Purchase Orders item.
- `frontend/src/App.jsx` — remove the `ApprovalQueue` import; redirect `/admin/approval-queue` and `/admin/purchase-orders` to `/admin/draft-po-creator`.
- `frontend/src/admin/pages/Dashboard.jsx` — repoint the approval button to `/admin/draft-po-creator`.
- `frontend/src/admin/pages/AlertsNotices.jsx` — repoint the queue button to `/admin/draft-po-creator`.
- `frontend/src/admin/utils/roles.js` — remove the dead `/admin/approval-queue` ROUTE_PERMISSION entry (and the orphan `canActOnApprovalQueue` helper in the optional cleanup task).

**Create:**
- `frontend/src/admin/utils/navBundles.test.js` — unit test asserting no Approval Queue bundle remains.

**Delete:**
- `frontend/src/admin/pages/ApprovalQueue.jsx` (Task 5).
- (Optional cleanup, Task 7) `frontend/src/admin/pages/DraftPOCreator.jsx` (orphan, not routed) and the orphaned localStorage PO-queue helpers in `frontend/src/admin/utils/approvalNotifications.js`.

**Do NOT touch (verified out of scope):** the realtime `ApprovalQueueChanged` event, `RealtimeBroadcaster.php`, `realtimeChannel.js`, and the `.approval-queue.changed` bell wiring. That event fires on PO submission and is consumed by the notification bell; it is unrelated to the Approval Queue *page*.

---

## Task 1: Add PO approve/reject logic to `PurchaseOrderPage`

This is purely additive — the Purchase Orders page keeps working while we wire up approval, before anything is removed.

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx`

- [ ] **Step 1: Add the imports the new logic needs**

In the API import block (currently lines 7–14), add `statusAPI`:

```jsx
import {
  suppliersAPI,
  purchaseOrdersAPI,
  purchaseRequestsAPI,
  locationsAPI,
  usersAPI,
  companySettingsAPI,
  statusAPI,
} from '../services/api';
```

Find the confirm import (line 23):

```jsx
import { confirmAction } from '../utils/confirm';
```

Leave it as-is — Task 1 uses only `confirmAction` (plain confirmation, no reason; the PO update endpoint has no `rejection_reason` field).

- [ ] **Step 2: Add approval permission flag + action-loading state**

Find this block (around lines 100–105):

```jsx
  // Purchase order creation: admin and inventory analyst.
  const canCreatePO = isAdmin || (typeof can === 'function' && can('purchase_order_creation', 'create'));

  // Tabs
  const [activeTab, setActiveTab] = useState('create');
```

Replace it with:

```jsx
  // Purchase order creation: admin and inventory analyst.
  const canCreatePO = isAdmin || (typeof can === 'function' && can('purchase_order_creation', 'create'));
  // Purchase order approval: admin and branch manager (update permission). Mirrors the
  // gate the retired Approval Queue used for approve/reject.
  const canApprovePO = isAdmin || (typeof can === 'function' && can('purchase_order_creation', 'update'));

  // Tabs
  const [activeTab, setActiveTab] = useState('create');

  // Per-PO approve/reject in-flight flag (holds the po_id being acted on)
  const [poActionLoading, setPoActionLoading] = useState(null);
```

- [ ] **Step 3: Add the approve and reject handlers**

Insert these two `useCallback`s immediately after `handleArchivePO` (which ends around line 357, just before the `renderPoStatusView` comment):

```jsx
  // ── Approve / Reject pending PO ─────────────────────────────────────────────
  const refreshPOStatusList = useCallback(async () => {
    const res = await purchaseOrdersAPI.getAll({ per_page: 100 });
    setPoStatusList(Array.isArray(res?.data) ? res.data : []);
  }, []);

  const handleApprovePO = useCallback(async (po) => {
    setPoActionLoading(po.po_id);
    try {
      const stRes = await statusAPI.getAll({ category: 'purchase_order' });
      const stRows = Array.isArray(stRes?.data) ? stRes.data : [];
      const authorizedId = stRows.find((s) => {
        const n = String(s.status_name || '').toLowerCase();
        return n === 'authorized' || n.includes('authori');
      })?.status_id;
      if (!authorizedId) {
        toast.error("Couldn't authorize PO", 'No "Authorized" status for purchase orders. Run backend migrations, then try again.');
        return;
      }
      await purchaseOrdersAPI.update(String(po.po_id), {
        status_id: authorizedId,
        ...(authUser?.user_id ? { approved_by: authUser.user_id } : {}),
      });
      await refreshPOStatusList();
      setSelectedPO(null);
      toast.success('PO authorized', `${po.po_number} is now Approved.`);
    } catch (err) {
      if (err?.status === 403) {
        toast.error("Couldn't authorize PO", 'This account cannot update purchase orders.');
        return;
      }
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : err?.message || 'Could not authorize this PO.';
      toast.error("Couldn't authorize PO", msg);
    } finally {
      setPoActionLoading(null);
    }
  }, [authUser, refreshPOStatusList]);

  const handleRejectPO = useCallback(async (po) => {
    if (!(await confirmAction({
      title: 'Reject this purchase order?',
      text: `${po.po_number} will be marked Rejected and cannot proceed to receiving.`,
      confirmText: 'Reject',
      tone: 'danger',
    }))) return;
    setPoActionLoading(po.po_id);
    try {
      const stRes = await statusAPI.getAll({ category: 'purchase_order' });
      const stRows = Array.isArray(stRes?.data) ? stRes.data : [];
      const rejectedId = stRows.find((s) => {
        const n = String(s.status_name || '').toLowerCase();
        return n === 'rejected' || n.includes('reject');
      })?.status_id;
      if (!rejectedId) {
        toast.error("Couldn't reject PO", 'No "Rejected" status for purchase orders. Run backend migrations, then try again.');
        return;
      }
      await purchaseOrdersAPI.update(String(po.po_id), {
        status_id: rejectedId,
        ...(authUser?.user_id ? { approved_by: authUser.user_id } : {}),
      });
      await refreshPOStatusList();
      setSelectedPO(null);
      toast.success('PO rejected', `${po.po_number} has been rejected.`);
    } catch (err) {
      if (err?.status === 403) {
        toast.error("Couldn't reject PO", 'This account cannot update purchase orders.');
        return;
      }
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : err?.message || 'Could not reject this PO.';
      toast.error("Couldn't reject PO", msg);
    } finally {
      setPoActionLoading(null);
    }
  }, [authUser, refreshPOStatusList]);
```

- [ ] **Step 4: Verify the project still compiles**

Run: `cd frontend && npm run build`
Expected: build succeeds (no "statusAPI is not exported" / unused-var errors). The handlers are defined but not yet rendered — that is fine.

> If `npm run build` reports `statusAPI` is not an export of `../services/api`, open `frontend/src/admin/services/api.js`, confirm the exact exported name for the status-lookup API (e.g. `statusAPI` / `statusesAPI`), and use that name consistently in Step 1 and Step 3.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): add approve/reject handlers to Purchase Orders page"
```

---

## Task 2: Render approve/reject on the PO Status cards

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (inside `renderPoStatusView`, the `po-status-card-right` block, ~lines 432–443)

- [ ] **Step 1: Add the buttons next to VIEW DETAILS**

Find this block (around lines 432–443):

```jsx
                <div className="po-status-card-right">
                  <span className="po-status-total-label">TOTAL AMOUNT</span>
                  <span className="po-status-total-amount">₱{amount.toLocaleString('en-PH')}</span>
                  <button type="button" className="po-status-view-btn" onClick={() => setSelectedPO(po)}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    VIEW DETAILS
                  </button>
                  <ArchiveButton
                    onArchive={() => handleArchivePO(po.po_id)}
                    disabled={!['completed', 'rejected', 'cancelled'].includes(statusCfg.color)}
                  />
                </div>
```

Replace it with (adds the pending-only approve/reject controls):

```jsx
                <div className="po-status-card-right">
                  <span className="po-status-total-label">TOTAL AMOUNT</span>
                  <span className="po-status-total-amount">₱{amount.toLocaleString('en-PH')}</span>
                  <button type="button" className="po-status-view-btn" onClick={() => setSelectedPO(po)}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    VIEW DETAILS
                  </button>
                  {statusCfg.color === 'pending' && canApprovePO && (
                    <div className="po-status-approve-actions">
                      <button
                        type="button"
                        className="aq-btn-reject"
                        disabled={poActionLoading === po.po_id}
                        onClick={() => handleRejectPO(po)}
                      >
                        REJECT
                      </button>
                      <button
                        type="button"
                        className="aq-btn-approve"
                        disabled={poActionLoading === po.po_id}
                        onClick={() => handleApprovePO(po)}
                      >
                        {poActionLoading === po.po_id ? 'SAVING…' : 'APPROVE'}
                      </button>
                    </div>
                  )}
                  <ArchiveButton
                    onArchive={() => handleArchivePO(po.po_id)}
                    disabled={!['completed', 'rejected', 'cancelled'].includes(statusCfg.color)}
                  />
                </div>
```

> The `aq-btn-reject` / `aq-btn-approve` classes are defined in `frontend/src/admin/styles/approval_queue.css`. That stylesheet is going away with the page, but `PurchaseOrderPage` imports `po_recommendation.css`, which already defines `aq-btn-approve` / `aq-btn-reject` / the `aq-modal-backdrop` rules used by the old modal (see `po_recommendation.css:2266` "Used in ApprovalQueue"). Confirm those button classes resolve after the page is deleted in Task 5 — if the buttons render unstyled, add minimal `.po-status-approve-actions { display:flex; gap:8px; }` plus button styles to `po_recommendation.css`.

- [ ] **Step 2: Build**

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): show approve/reject on pending PO status cards"
```

---

## Task 3: Render approve/reject in the PO detail modal

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseOrderPage.jsx` (the `po-detail-footer` block at the end of the detail `Modal`, ~lines 1214–1226)

- [ ] **Step 1: Add pending approve/reject to the modal footer**

Find the footer return inside the detail modal (around lines 1214–1226):

```jsx
                return (
                  <div className="po-detail-footer">
                    <button type="button" className="po-detail-btn po-detail-btn--excel" onClick={() => exportPOToExcel(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                      EXPORT EXCEL
                    </button>
                    <button type="button" className="po-detail-btn po-detail-btn--doc" onClick={() => exportPOToDocument(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      EXPORT DOCUMENT
                    </button>
                  </div>
                );
```

Replace it with (adds pending approve/reject before the export buttons):

```jsx
                return (
                  <div className="po-detail-footer">
                    {statusCfg.color === 'pending' && canApprovePO && (
                      <>
                        <button
                          type="button"
                          className="aq-btn-reject"
                          disabled={poActionLoading === d.po_id}
                          onClick={() => handleRejectPO(d)}
                        >
                          REJECT
                        </button>
                        <button
                          type="button"
                          className="aq-btn-approve"
                          disabled={poActionLoading === d.po_id}
                          onClick={() => handleApprovePO(d)}
                        >
                          {poActionLoading === d.po_id ? 'SAVING…' : 'APPROVE'}
                        </button>
                      </>
                    )}
                    <button type="button" className="po-detail-btn po-detail-btn--excel" onClick={() => exportPOToExcel(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                      EXPORT EXCEL
                    </button>
                    <button type="button" className="po-detail-btn po-detail-btn--doc" onClick={() => exportPOToDocument(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      EXPORT DOCUMENT
                    </button>
                  </div>
                );
```

`d` is the selected PO object already in scope in this block (`const d = selectedPO;` near line 1005); `statusCfg` is already computed there too.

- [ ] **Step 2: Build**

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 3: Manual verification (approval works end-to-end)**

Start the app (`cd frontend && npm start`, backend running) and sign in as an admin or branch manager.
1. Go to **Purchase Orders → PO Status**. A pending PO shows **Reject** and **Approve** on its card.
2. Click **Approve** → toast "PO authorized"; the card flips to APPROVED.
3. Open another pending PO via **View Details** → footer shows Reject/Approve. Click **Reject** → confirm → toast "PO rejected"; status becomes REJECTED.
4. Sign in as a user **without** `purchase_order_creation.update` (e.g. inventory analyst) → no Approve/Reject buttons appear on cards or in the modal.

Expected: all four behaviors hold.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat(po): show approve/reject in PO detail modal"
```

---

## Task 4: Remove the Approval Queue nav-bundle entries

**Files:**
- Create: `frontend/src/admin/utils/navBundles.test.js`
- Modify: `frontend/src/admin/utils/navBundles.js`
- Modify: `back-end/database/seeders/NavBundleSeeder.php`

- [ ] **Step 1: Write a failing test for the frontend nav bundle**

Create `frontend/src/admin/utils/navBundles.test.js`:

```jsx
import { NAV_BUNDLES } from './navBundles';

describe('NAV_BUNDLES', () => {
  test('does not contain the retired Approval Queue page', () => {
    const paths = NAV_BUNDLES.map((b) => b.routePath);
    expect(paths).not.toContain('/admin/approval-queue');
    expect(NAV_BUNDLES.some((b) => b.name === 'Approval Queue')).toBe(false);
  });

  test('still exposes the Purchase Orders page', () => {
    expect(NAV_BUNDLES.some((b) => b.routePath === '/admin/draft-po-creator')).toBe(true);
  });
});
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `cd frontend && npx react-scripts test src/admin/utils/navBundles.test.js --watchAll=false`
Expected: FAIL — the first test fails because `/admin/approval-queue` is still present.

- [ ] **Step 3: Remove the entry from `navBundles.js`**

Delete this line (line 3):

```jsx
  { name: 'Approval Queue',        routePath: '/admin/approval-queue',   required: ['purchase_order_creation.read'] },
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `cd frontend && npx react-scripts test src/admin/utils/navBundles.test.js --watchAll=false`
Expected: PASS (both tests).

- [ ] **Step 5: Remove the entry from the backend seeder**

In `back-end/database/seeders/NavBundleSeeder.php`, delete this row (line 21):

```php
            ['Approval Queue',       '/admin/approval-queue',       'ADMINISTRATION',          'approval',           'purchase_order_creation', 'read'],
```

- [ ] **Step 6: Re-seed the dev nav-bundle table**

Run: `cd back-end && php artisan db:seed --class=NavBundleSeeder`
Expected: seeder completes; the `nav_bundles` table no longer contains the `/admin/approval-queue` row. (If the seeder appends rather than syncs, confirm the Approval Queue row is gone — see the seeder's `truncate`/`updateOrCreate` logic; re-run is safe.)

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/utils/navBundles.js frontend/src/admin/utils/navBundles.test.js back-end/database/seeders/NavBundleSeeder.php
git commit -m "chore(nav): remove Approval Queue nav-bundle entry (front + seeder)"
```

---

## Task 5: Delete the page, redirect its routes, fix the sidebar

**Files:**
- Modify: `frontend/src/App.jsx`
- Modify: `frontend/src/admin/components/Sidebar.jsx`
- Delete: `frontend/src/admin/pages/ApprovalQueue.jsx`

- [ ] **Step 1: Remove the sidebar menu item**

In `frontend/src/admin/components/Sidebar.jsx`, delete this line from the ADMINISTRATION `items` array (line 64):

```jsx
      { name: 'Approval Queue', path: '/admin/approval-queue', icon: 'approval' },
```

- [ ] **Step 2: Move the pending-approval badge to the Purchase Orders item**

In `frontend/src/admin/components/Sidebar.jsx`, find the badge render (lines 506–510):

```jsx
              {item.path === '/admin/approval-queue' && pendingApprovalCount > 0 && (
                <span className="nav-item-badge">
                  {pendingApprovalCount > 99 ? '99+' : pendingApprovalCount}
                </span>
              )}
```

Replace the path with the Purchase Orders route:

```jsx
              {item.path === '/admin/draft-po-creator' && pendingApprovalCount > 0 && (
                <span className="nav-item-badge">
                  {pendingApprovalCount > 99 ? '99+' : pendingApprovalCount}
                </span>
              )}
```

- [ ] **Step 3: Redirect the two routes in `App.jsx`**

In `frontend/src/App.jsx`, replace the legacy alias route (line 124):

```jsx
          <Route path="/admin/purchase-orders" element={<RoleRoute><ApprovalQueue initialTab="purchase-orders" /></RoleRoute>} />
```

with:

```jsx
          <Route path="/admin/purchase-orders" element={<Navigate to="/admin/draft-po-creator" replace />} />
```

And replace the legacy route (line 161):

```jsx
          <Route path="/admin/approval-queue" element={<RoleRoute><ApprovalQueue /></RoleRoute>} />
```

with:

```jsx
          <Route path="/admin/approval-queue" element={<Navigate to="/admin/draft-po-creator" replace />} />
```

(`Navigate` is already imported in `App.jsx` line 2.)

- [ ] **Step 4: Remove the lazy import and delete the page file**

In `frontend/src/App.jsx`, delete the import (line 54):

```jsx
const ApprovalQueue = lazy(() => import('./admin/pages/ApprovalQueue'));
```

Then delete the file:

```bash
git rm frontend/src/admin/pages/ApprovalQueue.jsx
```

- [ ] **Step 5: Confirm nothing else imports the page**

Run: `cd frontend && grep -rn "ApprovalQueue" src/ --include=*.jsx --include=*.js`
Expected: the only remaining match is the realtime event name string in `realtimeChannel.js` / its test (`.approval-queue.changed`) and `approvalNotifications.js` helper names — **no** `import ... ApprovalQueue from ... pages/ApprovalQueue`. If a page import remains, remove it.

- [ ] **Step 6: Build**

Run: `cd frontend && npm run build`
Expected: build succeeds with no "Can't resolve './admin/pages/ApprovalQueue'" error.

- [ ] **Step 7: Manual verification (redirects + sidebar)**

With the app running: the sidebar has **no** "Approval Queue" item; the pending count badge now sits on **Purchase Orders**. Navigating to `/admin/approval-queue` and `/admin/purchase-orders` in the address bar both land on the Purchase Orders page (no 404, no old queue UI).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/App.jsx frontend/src/admin/components/Sidebar.jsx
git commit -m "feat(nav): retire Approval Queue page; redirect routes; move badge to Purchase Orders"
```

---

## Task 6: Repoint stray links to the Purchase Orders page

**Files:**
- Modify: `frontend/src/admin/pages/Dashboard.jsx` (line ~344)
- Modify: `frontend/src/admin/pages/AlertsNotices.jsx` (line ~546)
- Modify: `frontend/src/admin/utils/roles.js` (line ~78)

- [ ] **Step 1: Repoint the Dashboard approval button**

In `frontend/src/admin/pages/Dashboard.jsx`, find (line ~344):

```jsx
          <button type="button" className="approval-alert-btn" onClick={() => navigate('/admin/approval-queue')}>
```

Change the target to:

```jsx
          <button type="button" className="approval-alert-btn" onClick={() => navigate('/admin/draft-po-creator')}>
```

- [ ] **Step 2: Repoint the AlertsNotices queue button**

In `frontend/src/admin/pages/AlertsNotices.jsx`, find (line ~546):

```jsx
            <button type="button" className="an-go-queue-btn" onClick={() => navigate('/admin/approval-queue')}>
```

Change the target to:

```jsx
            <button type="button" className="an-go-queue-btn" onClick={() => navigate('/admin/draft-po-creator')}>
```

- [ ] **Step 3: Remove the dead ROUTE_PERMISSION entry in roles.js**

In `frontend/src/admin/utils/roles.js`, delete the now-unreachable route mapping (line ~78):

```jsx
  '/admin/approval-queue': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.AUDITOR, ROLES.INVENTORY_ANALYST],
```

(The route is now a redirect with no `RoleRoute`, so this entry is dead. Leave the explanatory comment on line 77 or remove it too — your call; removing keeps the file tidy.)

- [ ] **Step 4: Confirm no remaining live navigation to the old path**

Run: `cd frontend && grep -rn "/admin/approval-queue" src/ --include=*.jsx --include=*.js`
Expected: matches only in `.env.development` comment context are not in `src/`; within `src/` the only remaining matches should be the redirect targets in `App.jsx`. No `navigate('/admin/approval-queue')` calls remain. (The `?tab=restock-requests` reference in `DraftPOCreator.jsx` is handled in the optional Task 7.)

- [ ] **Step 5: Build**

Run: `cd frontend && npm run build`
Expected: build succeeds.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/Dashboard.jsx frontend/src/admin/pages/AlertsNotices.jsx frontend/src/admin/utils/roles.js
git commit -m "chore(nav): repoint Approval Queue links to Purchase Orders page"
```

---

## Task 7 (OPTIONAL cleanup): Remove orphaned helpers and the unrouted DraftPOCreator

Only do this if you want the dead-code removal the spec flags. It is independent of the feature and can be skipped without affecting behavior. `DraftPOCreator.jsx` is **not routed** anywhere (verified: it only self-references), and it is the sole live-looking importer of the localStorage PO-queue helpers. `canActOnApprovalQueue` is **never imported** (verified).

**Files:**
- Delete: `frontend/src/admin/pages/DraftPOCreator.jsx`
- Modify: `frontend/src/admin/utils/approvalNotifications.js`
- Modify: `frontend/src/admin/utils/roles.js`

- [ ] **Step 1: Re-confirm the orphans before deleting**

Run:
```bash
cd frontend && grep -rn "DraftPOCreator" src/ --include=*.jsx --include=*.js
cd frontend && grep -rn "canActOnApprovalQueue" src/ --include=*.jsx --include=*.js
```
Expected: `DraftPOCreator` appears only inside `pages/DraftPOCreator.jsx` itself; `canActOnApprovalQueue` appears only at its definition in `roles.js`. If either has other importers, STOP and do not delete it.

- [ ] **Step 2: Delete the orphan page**

```bash
git rm frontend/src/admin/pages/DraftPOCreator.jsx
```

- [ ] **Step 3: Remove the now-unused PO-queue helpers**

In `frontend/src/admin/utils/approvalNotifications.js`, delete these exports (they were only used by the deleted `DraftPOCreator.jsx`): `APPROVAL_QUEUE_STORAGE_KEY`, `APPROVAL_QUEUE_KEY`, `getApprovalQueuePurchaseOrders`, `saveApprovalQueuePurchaseOrders`, `addApprovalQueuePurchaseOrder`, `updateApprovalQueuePurchaseOrderStatus`, `mergeApprovalQueuePurchaseOrder`, `removeApprovalQueuePurchaseOrder` (lines 1–2 and 21–68).

**Keep** the system-notification helpers (`getSystemNotifications` … `markNotificationRead`) and the restock helpers (`getApprovalQueueRestockRequests` … `updateApprovalQueueRestockRequestStatus`) unless a grep shows they are also orphaned:

```bash
cd frontend && grep -rn "getApprovalQueueRestockRequests\|addApprovalQueueRestockRequest\|getSystemNotifications\|addSystemNotification\|getUnreadNotificationCount\|markAllNotificationsRead\|markNotificationRead" src/ --include=*.jsx --include=*.js
```
Delete only the ones with no remaining importer.

- [ ] **Step 4: Remove the orphan roles helper**

In `frontend/src/admin/utils/roles.js`, delete the `canActOnApprovalQueue` export (the function starting at line ~145). Verify nothing imports it (Step 1 already confirmed).

- [ ] **Step 5: Build + run the util test suite**

Run: `cd frontend && npm run build`
Expected: build succeeds with no unresolved-import / unused-export errors.

Run: `cd frontend && npx react-scripts test src/admin/utils --watchAll=false`
Expected: PASS (including `navBundles.test.js`).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/utils/approvalNotifications.js frontend/src/admin/utils/roles.js
git commit -m "chore: remove orphaned DraftPOCreator page and dead approval-queue helpers"
```

---

## Final verification

- [ ] `cd frontend && npm run build` succeeds.
- [ ] `cd frontend && npx react-scripts test src/admin/utils/navBundles.test.js --watchAll=false` passes.
- [ ] Sidebar shows no "Approval Queue"; the pending badge is on "Purchase Orders".
- [ ] `/admin/approval-queue` and `/admin/purchase-orders` both redirect to `/admin/draft-po-creator`.
- [ ] On Purchase Orders → PO Status, an approver sees Approve/Reject on pending POs (card + modal); a non-approver does not; approving → Authorized, rejecting → Rejected, list refreshes.
- [ ] `grep -rn "pages/ApprovalQueue" frontend/src` returns nothing.
