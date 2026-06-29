# Warranty Claim: Sidebar → In-Records Button Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the standalone "Warranty Claims" sidebar item; users reach the claim flow only via a "Claim Warranty" button on the Warranty Records page (route + permission gate unchanged).

**Architecture:** Pure edits to existing files — relabel three navigation buttons on the records page, delete the dead in-page claim-notes modal, and remove the "Warranty Claims" nav entry from the static sidebar list, the frontend nav-bundle mirror, and the backend seeder (plus a one-off DB cleanup of the already-seeded row). No new files, no route or permission changes.

**Tech Stack:** React (CRA, `frontend/`), Laravel 10 + MariaDB (`back-end/`), PHPUnit for backend tests. The frontend has no JS test harness, so verification is via build + manual checks; backend changes are covered by the existing PHPUnit suite.

**Spec:** `docs/superpowers/specs/2026-06-29-warranty-claim-sidebar-to-button-design.md`

**Note on TDD:** This change is UI relabeling, dead-code removal, and nav-config edits. There is no frontend test runner in this repo, so frontend tasks use compile/build + manual verification instead of unit tests. The backend task relies on the existing PHPUnit suite (no behavior change is expected — the test simply confirms nothing broke).

---

## File Structure

Files touched (all existing — nothing created):

- `frontend/src/admin/pages/WarrantyManagement.jsx` — relabel 3 nav buttons; delete dead claim-notes modal + handlers + state.
- `frontend/src/admin/components/Sidebar.jsx` — remove `Warranty Claims` item from `adminMenuSections`.
- `frontend/src/admin/utils/navBundles.js` — remove `Warranty Claims` entry from `NAV_BUNDLES`.
- `back-end/database/seeders/NavBundleSeeder.php` — remove the `Warranty Claims` bundle row.
- Dev DB (`airkingV5`) — one-off deletion of the existing `/admin/warranty-claims` `nav_permission_bundles` row and its `nav_bundle_permissions` link (seeder only upserts, never deletes).

Left intentionally untouched: `WarrantyClaimPage.jsx`, `back-end/routes/api.php`, `PermissionSeeder.php`, RBAC tests, the `warranty_claims` permission, the backend `markClaimed` endpoint/controller, and `warrantiesAPI.markClaimed` in `frontend/src/admin/services/api.js` (becomes unused but harmless — flagged for a future cleanup pass).

---

## Task 1: Relabel the three navigation buttons on Warranty Records

**Files:**
- Modify: `frontend/src/admin/pages/WarrantyManagement.jsx`

All three buttons already call `openClaimModal(...)`, which navigates to
`/admin/warranty-claims?serial=…`. Only their visible text changes.

- [ ] **Step 1: Relabel the per-row button**

In the table body, find the per-row claim button (inside `warranties.map`, the
`status_id === 33` block):

```jsx
                          <button
                            className="war-btn-assign"
                            onClick={() => openClaimModal(w.warranty_id, w.product?.product_name ?? w.serial_number, w.serial_number)}
                          >
                            Claim
                          </button>
```

Change the button text `Claim` to `Claim Warranty`:

```jsx
                          <button
                            className="war-btn-assign"
                            onClick={() => openClaimModal(w.warranty_id, w.product?.product_name ?? w.serial_number, w.serial_number)}
                          >
                            Claim Warranty
                          </button>
```

- [ ] **Step 2: Relabel the serial-lookup result button**

In the serial-lookup result block (rendered when `srClaimable`), find:

```jsx
                  <button
                    type="button"
                    className="war-btn-assign"
                    onClick={() => openClaimModal(sr.warranty_id, sr.product?.product_name ?? sr.serial_number, sr.serial_number)}
                  >
                    Mark as Claimed
                  </button>
```

Change `Mark as Claimed` to `Claim Warranty`:

```jsx
                  <button
                    type="button"
                    className="war-btn-assign"
                    onClick={() => openClaimModal(sr.warranty_id, sr.product?.product_name ?? sr.serial_number, sr.serial_number)}
                  >
                    Claim Warranty
                  </button>
```

- [ ] **Step 3: Relabel the View-modal button**

In the View modal footer, find the `status_id === 33` button:

```jsx
                <button
                  type="button"
                  className="btn-save"
                  onClick={() => openClaimModal(viewingWarranty.warranty_id, viewingWarranty.product?.product_name ?? viewingWarranty.serial_number, viewingWarranty.serial_number)}
                >
                  Mark as Claimed
                </button>
```

Change `Mark as Claimed` to `Claim Warranty`:

```jsx
                <button
                  type="button"
                  className="btn-save"
                  onClick={() => openClaimModal(viewingWarranty.warranty_id, viewingWarranty.product?.product_name ?? viewingWarranty.serial_number, viewingWarranty.serial_number)}
                >
                  Claim Warranty
                </button>
```

- [ ] **Step 4: Verify no other "Mark as Claimed" / standalone "Claim" labels remain**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "Mark as Claimed\|>Claim<" -- frontend/src/admin/pages/WarrantyManagement.jsx`
Expected: no output (all three relabeled). Note: the claim-notes modal title "Mark Warranty as Claimed" is removed in Task 2, not here — if it still matches at this point that is expected and fine.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/WarrantyManagement.jsx
git commit -m "feat(warranty): relabel records claim buttons to 'Claim Warranty'"
```

---

## Task 2: Remove the dead claim-notes modal and its handlers

**Files:**
- Modify: `frontend/src/admin/pages/WarrantyManagement.jsx`

`openClaimModal` navigates instead of setting `claimTarget`, so `claimTarget` is never
truthy — the "Mark Warranty as Claimed" modal and its handlers are unreachable.

- [ ] **Step 1: Remove the claim-notes modal state declarations**

Find and delete these state lines (near the top of the component, after the serial-lookup state):

```jsx
  // Claim notes modal
  const [claimTarget, setClaimTarget]   = useState(null);  // { id, name }
  const [claimNotes, setClaimNotes]     = useState('');
  const [claimError, setClaimError]     = useState('');
  const [claiming, setClaiming]         = useState(false);
```

- [ ] **Step 2: Remove the `submitClaim` function**

Find and delete the entire `submitClaim` function:

```jsx
  const submitClaim = async () => {
    if (!claimNotes.trim() || claimNotes.trim().length < 10) {
      setClaimError('Claim notes are required (minimum 10 characters).');
      return;
    }
    setClaiming(true);
    setClaimError('');
    try {
      await warrantiesAPI.markClaimed(claimTarget.id, { claim_notes: claimNotes.trim() });
      toast.success('Warranty marked as Claimed.');
      setClaimTarget(null);
      setViewingWarranty(null);
      setSerialResult(null);
      fetchWarranties(currentPage);
    } catch (err) {
      setClaimError(err.message || 'Failed to mark as claimed.');
    } finally {
      setClaiming(false);
    }
  };
```

- [ ] **Step 3: Remove the claim-notes `<Modal>` block**

Find and delete the entire dead modal at the bottom of the JSX (the `{claimTarget && ( … )}` block):

```jsx
      {/* Claim notes modal */}
      {claimTarget && (
        <Modal isOpen title="Mark Warranty as Claimed" onClose={() => setClaimTarget(null)}>
          <div style={{ padding: '4px 0' }}>
            <p style={{ marginBottom: 12, color: '#374151', fontSize: 14 }}>
              Record claim details for: <strong>{claimTarget.name}</strong>
            </p>
            <div style={{ marginBottom: 16 }}>
              <label style={{ display: 'block', marginBottom: 6, fontSize: 13, fontWeight: 600, color: '#374151' }}>
                Claim Notes *
              </label>
              <textarea
                value={claimNotes}
                onChange={(e) => { setClaimNotes(e.target.value); setClaimError(''); }}
                placeholder="Describe the claim reason, defect, or service performed (minimum 10 characters)..."
                rows={4}
                style={{ width: '100%', padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }}
              />
              {claimError && (
                <p style={{ marginTop: 4, color: '#dc2626', fontSize: 12 }}>{claimError}</p>
              )}
            </div>
            <div className="modal-footer">
              <button type="button" className="btn-cancel" onClick={() => setClaimTarget(null)}>Cancel</button>
              <button type="button" className="btn-save" onClick={submitClaim} disabled={claiming}>
                {claiming ? 'Processing...' : 'Confirm Claim'}
              </button>
            </div>
          </div>
        </Modal>
      )}
```

- [ ] **Step 4: Verify no dangling references remain**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "claimTarget\|setClaimTarget\|claimNotes\|claimError\|setClaiming\|submitClaim\|markClaimed" -- frontend/src/admin/pages/WarrantyManagement.jsx`
Expected: no output. (`markClaimed` was only referenced by the deleted `submitClaim`.)

- [ ] **Step 5: Verify the file still compiles (lint/build)**

Run: `cd C:/XAMPP1/htdocs/Airking_Inventory/frontend && npx eslint src/admin/pages/WarrantyManagement.jsx`
Expected: no errors. (If eslint is not configured to run standalone here, instead run `npm run build` and confirm it compiles without errors referencing this file.)

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/WarrantyManagement.jsx
git commit -m "refactor(warranty): remove dead in-page claim-notes modal"
```

---

## Task 3: Remove "Warranty Claims" from the static sidebar list

**Files:**
- Modify: `frontend/src/admin/components/Sidebar.jsx`

- [ ] **Step 1: Delete the sidebar item**

In `adminMenuSections`, COMPLIANCE & SUPPORT section, find and delete this line:

```jsx
      { name: 'Warranty Claims', path: '/admin/warranty-claims', icon: 'warranty' },
```

Leave the adjacent `Warranty Records` item (`/admin/warranties`) in place.

- [ ] **Step 2: (Optional) Leave the route-prefetch map entry**

Do NOT remove the `'/admin/warranty-claims': () => import('../pages/WarrantyClaimPage')` line from `routePrefetchMap` — the route still exists and the records buttons navigate to it, so prefetch remains valid.

- [ ] **Step 3: Verify the sidebar no longer lists the item**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "Warranty Claims" -- frontend/src/admin/components/Sidebar.jsx`
Expected: no output.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/Sidebar.jsx
git commit -m "feat(nav): remove Warranty Claims from static sidebar list"
```

---

## Task 4: Remove "Warranty Claims" from the frontend nav-bundle mirror

**Files:**
- Modify: `frontend/src/admin/utils/navBundles.js`

- [ ] **Step 1: Delete the nav-bundle entry**

In `NAV_BUNDLES`, find and delete this line:

```js
  { name: 'Warranty Claims',       routePath: '/admin/warranty-claims',  required: ['warranty_claims.read'] },
```

Leave the `Warranty Records` entry (`/admin/warranties`) in place.

- [ ] **Step 2: Verify it is gone**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "warranty-claims" -- frontend/src/admin/utils/navBundles.js`
Expected: no output.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/utils/navBundles.js
git commit -m "feat(nav): remove Warranty Claims from frontend nav-bundle mirror"
```

---

## Task 5: Remove "Warranty Claims" from the backend seeder

**Files:**
- Modify: `back-end/database/seeders/NavBundleSeeder.php`

- [ ] **Step 1: Delete the seeder bundle row**

In the `bundles()` array, COMPLIANCE & SUPPORT block, find and delete this line:

```php
            ['Warranty Claims',      '/admin/warranty-claims',      'COMPLIANCE & SUPPORT',    'warranty',           'warranty_claims',         'read'],
```

Leave the `Warranty Records` row (`/admin/warranties`) in place.

- [ ] **Step 2: Verify it is gone from the seeder**

Run: `git -C C:/XAMPP1/htdocs/Airking_Inventory grep -n "warranty-claims" -- back-end/database/seeders/NavBundleSeeder.php`
Expected: no output.

- [ ] **Step 3: Run the backend test suite to confirm nothing broke**

Run: `cd C:/XAMPP1/htdocs/Airking_Inventory/back-end && php artisan test --testsuite=Feature`
Expected: PASS (no test asserts nav-bundle count; RBAC route-matrix for `warranty-claims` is unaffected because the route/permission are unchanged).

- [ ] **Step 4: Commit**

```bash
git add back-end/database/seeders/NavBundleSeeder.php
git commit -m "feat(nav): remove Warranty Claims bundle from NavBundleSeeder"
```

---

## Task 6: Remove the already-seeded Warranty Claims bundle from the dev DB

**Files:**
- None (data-only change to the `airkingV5` dev DB).

`NavBundleSeeder` only upserts and never deletes, so the row seeded earlier still exists in
the dev DB. Remove it (and its permission link) directly.

- [ ] **Step 1: Confirm the stale row exists**

Run:
```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/back-end && php artisan tinker --execute="echo \App\Models\NavPermissionBundle::where('route_path','/admin/warranty-claims')->count();"
```
Expected: `1` (the stale row is present).

- [ ] **Step 2: Delete the bundle and its permission link**

Run:
```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/back-end && php artisan tinker --execute="\$b = \App\Models\NavPermissionBundle::where('route_path','/admin/warranty-claims')->first(); if (\$b) { \$b->permissions()->detach(); \$b->delete(); echo 'deleted'; } else { echo 'not found'; }"
```
Expected: `deleted`. (`permissions()->detach()` clears the `nav_bundle_permissions` link first, then the bundle row is removed.)

- [ ] **Step 3: Verify removal**

Run:
```bash
cd C:/XAMPP1/htdocs/Airking_Inventory/back-end && php artisan tinker --execute="echo \App\Models\NavPermissionBundle::where('route_path','/admin/warranty-claims')->count();"
```
Expected: `0`.

- [ ] **Step 4: (No commit)**

This is a dev-DB data change, not a code change — nothing to commit. The schema/seeder change was already committed in Task 5.

---

## Task 7: Full manual verification

**Files:**
- None (manual QA).

- [ ] **Step 1: Rebuild / serve the frontend**

Run: `cd C:/XAMPP1/htdocs/Airking_Inventory/frontend && npm start` (or your usual dev-serve command).
Expected: compiles with no errors.

- [ ] **Step 2: Sidebar check**

Log in as an admin. Open the sidebar → COMPLIANCE & SUPPORT.
Expected: "Warranty Records" is present; "Warranty Claims" is gone.

- [ ] **Step 3: Per-row navigation check**

Go to Warranty Records. On an **Active** warranty row, click **"Claim Warranty"**.
Expected: navigates to the Warranty Claim page with the serial pre-filled in the form.

- [ ] **Step 4: Serial-lookup + View-modal navigation check**

Use the Serial Number Lookup with a claimable serial → click **"Claim Warranty"** on the result.
Then open a row's **View** modal on an Active warranty → click **"Claim Warranty"**.
Expected: both navigate to the Warranty Claim page with the serial pre-filled.

- [ ] **Step 5: Permission-gate check**

Log in as a user with `warranty_records` read but WITHOUT `warranty_claims` permission. Navigate
directly to `/admin/warranty-claims`.
Expected: access is blocked by the route's `RoleRoute`/permission gate (unchanged behavior).

- [ ] **Step 6: Done**

All checks pass → feature complete. Consider invoking superpowers:finishing-a-development-branch.
```
