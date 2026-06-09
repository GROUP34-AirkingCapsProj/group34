# Confirm + Toast Rollout Phase 2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the existing shared `confirm` + `toast` helpers to Purchase Order, Budget Allocation, Adjustment, Stock Transfer management, and the IST create/dispatch/receive pages — confirming submit/create/dispatch/receive and replacing static banners/alerts with toasts.

**Architecture:** Reuse `utils/confirm.js` (`confirmAction`/`confirmWithReason`) and `utils/toast.js` (`toast.success`/`toast.error`). No helper changes. Per-page edits: insert a confirm gate, route feedback through toast, and remove now-dead banner JSX/state with grep guards.

**Tech Stack:** React (CRA / react-scripts), SweetAlert2 (via the helpers), CSS.

---

## Shared conventions (all tasks)

- **Imports** (add only what's used; don't duplicate an existing `toast` import):
  ```js
  import { confirmAction } from '../utils/confirm';
  import { toast } from '../utils/toast';
  ```
- **Submit/create/dispatch/receive confirm** uses `tone: 'primary'`.
- **Toast copy:** success title = past-tense outcome; error = `toast.error("Couldn't <verb>", <reason>)`. Validation messages also go through `toast.error` (or `toast.warning` if a message is purely a "fill this in" nudge — implementer's judgement, but be consistent within a file).
- **Banner/state removal:** after switching to toast, delete the now-unused banner JSX and its backing state. BEFORE deleting any state variable, grep the file to confirm it's unused elsewhere:
  `git grep -nE "<identifier>" frontend/src/admin/pages/<File>.jsx`
  Keep `submitting`/`setSubmitting` (used for button disabled state). Do NOT remove CSS classes (`po-success-banner` etc.) — they are shared by other pages.
- **Build gate (per task, from `frontend/`):** `npx react-scripts build` → expect "Compiled" (warnings OK; NOT `CI=true`). Confirm no NEW unused-var warnings for identifiers you removed.
- **`async`:** any handler newly using `await confirmAction` must be `async` (these are already `useCallback(async …)` / `async` in the targets).

---

### Task 1: PurchaseOrderPage — submit confirm + banner→toast

**File:** `frontend/src/admin/pages/PurchaseOrderPage.jsx`

Current: `handleSubmitPO` (~308) uses `setSubmitError(...)` for validation + catch, and `setConvertSuccess({...})` for the success banner (~967). Error banner at ~954 uses `submitError` state (declared ~137). Success banner uses `convertSuccess` state.

- [ ] **Step 1: Add imports** (per conventions).

- [ ] **Step 2: Rewrite `handleSubmitPO`** — add a confirm gate at the top and swap banner state for toast. Replace the whole callback with:

```js
  const handleSubmitPO = useCallback(async () => {
    if (!selectedPR)         { toast.error("Couldn't create PO", 'Please select an approved Purchase Request first.'); return; }
    if (!selectedSupplierId) { toast.error("Couldn't create PO", 'Please select a supplier.'); return; }
    if (lineItems.length === 0) { toast.error("Couldn't create PO", 'No line items to submit.'); return; }
    if (!(await confirmAction({ title: 'Submit this purchase order?', confirmText: 'Submit PO', tone: 'primary' }))) return;
    setSubmitting(true);
    try {
      const payload = {
        supplier_id:            Number(selectedSupplierId),
        expected_delivery_date: expectedDeliveryDate || null,
        payment_terms:          paymentTerms,
        notes:                  notes.trim()          || null,
        references_note:        referencesNote.trim() || null,
        verified_by:            verifiedById ? Number(verifiedById) : null,
        ship_to_name:           shipToName.trim()     || null,
        ship_to_address:        shipToAddress.trim()  || null,
        details: lineItems.map(li => ({
          product_id:       li.product_id,
          quantity_ordered: li.quantity_ordered,
          unit_price:       li.unit_price,
          subtotal:         li.quantity_ordered * li.unit_price,
        })),
      };
      const res   = await purchaseRequestsAPI.convertToPO(selectedPR.pr_id, payload);
      const data  = res?.data;
      const poNum = data?.po_number || data?.po?.po_number || 'New PO';
      toast.success('Purchase Order created', `${poNum} from ${selectedPR.pr_number} — waiting for admin approval`);
      setApprovedPRs(prev => prev.filter(p => p.pr_id !== selectedPR.pr_id));
      setSelectedPR(null);
      setLineItems([]);
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to create purchase order.';
      toast.error("Couldn't create PO", msg);
    } finally {
      setSubmitting(false);
    }
  }, [selectedPR, selectedSupplierId, expectedDeliveryDate, lineItems, paymentTerms, notes, referencesNote, verifiedById, shipToName, shipToAddress]);
```

- [ ] **Step 3: Remove the error banner JSX** (~954–956):
```jsx
            {submitError && (
              <div className="po-submit-error" style={{ marginTop: 8 }}>{submitError}</div>
            )}
```

- [ ] **Step 4: Remove the success banner JSX** (~967–975, the `{convertSuccess && ( <div className="po-success-banner">…</div> )}` block).

- [ ] **Step 5: Remove dead state.** Delete the `submitError`/`setSubmitError` useState (~137) and the `convertSuccess`/`setConvertSuccess` useState. First guard each:
  `git grep -nE "submitError|setSubmitError|convertSuccess|setConvertSuccess" frontend/src/admin/pages/PurchaseOrderPage.jsx`
  After removal, this grep must return NO output. Keep `submitting`/`setSubmitting`.

- [ ] **Step 6: Build** (per conventions). Expect compiled, no new unused-var warnings for removed identifiers.

- [ ] **Step 7: Commit**
```bash
git add frontend/src/admin/pages/PurchaseOrderPage.jsx
git commit -m "feat: confirm + toast for Purchase Order submit (replace banners)"
```

---

### Task 2: BudgetManagementPage — create/edit confirm + banner→toast + alert→toast

**File:** `frontend/src/admin/pages/BudgetManagementPage.jsx`

Current: `handleCreate` (~205), `handleEditSave` (~255), `handleArchive` (~290, native `alert()` at ~295). Banners: `createError` (~663), a success banner (~706), `editError` (~772). Archive confirm already handled by `ArchiveButton`.

- [ ] **Step 1: Add imports** (per conventions).

- [ ] **Step 2: Read `handleCreate` and `handleEditSave` fully.** Identify the success-state variable backing the `po-success-banner` at ~706 and the `createError`/`editError` error state.

- [ ] **Step 3: Add confirm + toast to `handleCreate`.** At the top of `handleCreate`, after any cheap client-side validation (convert each validation failure to `toast.error("Couldn't create budget", '<message>')`), insert:
```js
    if (!(await confirmAction({ title: 'Create this budget allocation?', confirmText: 'Create', tone: 'primary' }))) return;
```
  On success replace the success-state setter with `toast.success('Budget created');`. In catch, replace the error-state setter with `toast.error("Couldn't create budget", err?.message || 'Failed to create budget.')`.

- [ ] **Step 4: Add confirm + toast to `handleEditSave`.** Same pattern with:
```js
    if (!(await confirmAction({ title: 'Save changes to this budget?', confirmText: 'Save', tone: 'primary' }))) return;
```
  Success → `toast.success('Budget updated');`. Catch → `toast.error("Couldn't save budget", err?.message || 'Failed to save budget.')`.

- [ ] **Step 5: Fix `handleArchive` error feedback.** Replace `alert(err?.message || 'Failed to delete budget.');` (~295) with:
```js
      toast.error("Couldn't archive budget", err?.message || 'Failed to delete budget.');
```
  If `handleArchive` has no success feedback, add `toast.success('Budget archived');` after the successful delete call.

- [ ] **Step 6: Remove the now-dead banner JSX + state.** Remove the `createError` block (~663), the success banner (~706), and the `editError` block (~772), plus their backing useState declarations. Grep-guard each identifier before deletion:
  `git grep -nE "createError|setCreateError|editError|setEditError|<successStateName>|<setSuccessStateName>" frontend/src/admin/pages/BudgetManagementPage.jsx`
  Must be empty after removal. Keep `submitting`/loading flags if present.

- [ ] **Step 7: Build** (per conventions).

- [ ] **Step 8: Commit**
```bash
git add frontend/src/admin/pages/BudgetManagementPage.jsx
git commit -m "feat: confirm + toast for budget create/edit; archive alert→toast"
```

---

### Task 3: AdjustmentManagement — create/update confirm

**File:** `frontend/src/admin/pages/AdjustmentManagement.jsx`

Current: `handleSubmit` (~83) already uses toast for create/update validation, success, and error. `handleArchive` (~107) deletes via `adjustmentsAPI.delete` and is already confirmed by `ArchiveButton`. Only gap: no confirm before create/update.

- [ ] **Step 1: Add the confirm import** (`toast` already imported):
```js
import { confirmAction } from '../utils/confirm';
```

- [ ] **Step 2: Read `handleSubmit` fully.** It does `e.preventDefault()` then validates (`toast.error('Add at least one product.')`) then submits (create vs update branch).

- [ ] **Step 3: Insert the confirm** after the existing client-side validation and before `setSubmitting(true)`/the API call. Use a title that reflects whether it's an edit or create if the handler already knows (e.g. via an `editing`/`isEdit` flag); otherwise:
```js
    if (!(await confirmAction({ title: 'Save this adjustment?', text: 'Inventory will be corrected accordingly.', confirmText: 'Save', tone: 'primary' }))) return;
```
  Keep `e.preventDefault()` at the very top (before any `await`). Do not change the existing toast.success/error calls.

- [ ] **Step 4: Build** (per conventions).

- [ ] **Step 5: Commit**
```bash
git add frontend/src/admin/pages/AdjustmentManagement.jsx
git commit -m "feat: confirm before saving an inventory adjustment"
```

---

### Task 4: StockTransferManagement — create/update confirm

**File:** `frontend/src/admin/pages/StockTransferManagement.jsx`

Current: `handleSubmit` (~239) already uses toast for validation/success/error. `handleArchive` (~267) is confirmed via `ArchiveButton`. Only gap: no confirm before create/update.

- [ ] **Step 1: Add the confirm import** (`toast` already imported):
```js
import { confirmAction } from '../utils/confirm';
```

- [ ] **Step 2: Read `handleSubmit` fully.** It validates (`From and To locations must be different.`, `Add at least one product.`) then submits, with a `shipFirst` branch affecting the success message.

- [ ] **Step 3: Insert the confirm** after the existing client-side validation and before the API call:
```js
    if (!(await confirmAction({ title: 'Save this transfer?', confirmText: 'Save', tone: 'primary' }))) return;
```
  Keep `e.preventDefault()` (if present) at the top before any await. Leave the existing toast.success/error calls unchanged.

- [ ] **Step 4: Build** (per conventions).

- [ ] **Step 5: Commit**
```bash
git add frontend/src/admin/pages/StockTransferManagement.jsx
git commit -m "feat: confirm before saving a stock transfer"
```

---

### Task 5: ISTCreatePage — submit confirm + toast feedback

**File:** `frontend/src/admin/pages/ISTCreatePage.jsx`

Current: `handleSubmit` (~70, `useCallback(async …)`). Determine its current feedback mechanism by reading it.

- [ ] **Step 1: Add imports** (per conventions; check whether `toast` is already imported first).

- [ ] **Step 2: Read `handleSubmit` fully** and note how it currently reports validation errors, success, and failure (inline state / banner / navigation).

- [ ] **Step 3: Insert the confirm** after cheap client-side validation (route any validation message through `toast.error`) and before the API call:
```js
    if (!(await confirmAction({ title: 'Create this interbranch transfer?', confirmText: 'Create IST', tone: 'primary' }))) return;
```

- [ ] **Step 4: Route feedback through toast.** On success, `toast.success('IST created', <number/next-step if available>)` (in addition to any existing navigation/redirect — keep navigation). On failure, `toast.error("Couldn't create IST", err?.message || '<existing fallback>')`. If the page used an inline error banner/state that is now redundant, remove it with a grep guard (per conventions); if the error display is interwoven with other UI, leave it and just ADD the toast — note this in your report.

- [ ] **Step 5: Build** (per conventions).

- [ ] **Step 6: Commit**
```bash
git add frontend/src/admin/pages/ISTCreatePage.jsx
git commit -m "feat: confirm + toast for IST creation"
```

---

### Task 6: ISTDispatchPage — confirm before dispatch + error banner→toast

**File:** `frontend/src/admin/pages/ISTDispatchPage.jsx`

Current: `handleConfirm` (~146) validates `totalScanned < 1` via `setSubmitError`, then `await istAPI.confirmDispatch(id)` and `setDispatched(true)` (full-page success screen). Error banner `po-submit-error` at ~395 backed by `submitError` state. KEEP the success screen.

- [ ] **Step 1: Add imports** (per conventions; check existing toast import).

- [ ] **Step 2: Rewrite `handleConfirm`:**
```js
  const handleConfirm = async () => {
    if (totalScanned < 1) {
      toast.error("Can't dispatch yet", 'Scan at least one unit before confirming dispatch.');
      return;
    }
    if (!(await confirmAction({
      title: `Confirm dispatch of ${ist?.transfer_number || 'this IST'}?`,
      text: 'Scanned units will be marked In Transit.',
      confirmText: 'Confirm dispatch',
      tone: 'primary',
    }))) return;
    setSubmitting(true);
    try {
      await istAPI.confirmDispatch(id);
      setDispatched(true);
    } catch (err) {
      toast.error("Couldn't confirm dispatch", err?.message || 'Failed to confirm dispatch.');
    } finally {
      setSubmitting(false);
    }
  };
```

- [ ] **Step 3: Remove the dispatch error banner JSX** at ~395 (`{submitError && <div className="po-submit-error" …>{submitError}</div>}`).

- [ ] **Step 4: Check the OTHER `po-submit-error` at ~221.** Read it — it is bound to the load-time `error` state (the `.catch((e) => setError(...))` at ~83), NOT `submitError`. LEAVE that load-error UI as-is (out of scope). Only remove the `submitError`-backed banner.

- [ ] **Step 5: Remove `submitError`/`setSubmitError` state** once the only consumer (the ~395 banner) is gone. Guard:
  `git grep -nE "submitError|setSubmitError" frontend/src/admin/pages/ISTDispatchPage.jsx` → must be empty after removal. Keep `error`/`setError` (load error) and `submitting`.

- [ ] **Step 6: Build** (per conventions).

- [ ] **Step 7: Commit**
```bash
git add frontend/src/admin/pages/ISTDispatchPage.jsx
git commit -m "feat: confirm before IST dispatch; dispatch error→toast"
```

---

### Task 7: ISTReceivePage — confirm before receive + error banner→toast

**File:** `frontend/src/admin/pages/ISTReceivePage.jsx`

Current: mirrors the dispatch page — `handleConfirm` (~196) with a validation guard + `istAPI` receive call + success screen, and a `po-submit-error` banner backed by `submitError`. KEEP the success screen.

- [ ] **Step 1: Add imports** (per conventions; check existing toast import).

- [ ] **Step 2: Read `handleConfirm` fully** to learn the exact receive API call (e.g. `istAPI.confirmReceive(id)` or similar), the validation guard, and the success-state setter.

- [ ] **Step 3: Rewrite `handleConfirm`** following the SAME shape as Task 6 Step 2, but for receive:
  - Validation guard → `toast.error("Can't receive yet", '<existing validation message>')`.
  - Confirm: `confirmAction({ title: \`Confirm receipt of ${ist?.transfer_number || 'this IST'}?\`, text: 'Scanned units will be added to destination stock.', confirmText: 'Confirm receipt', tone: 'primary' })` → return if false.
  - Keep the existing success-state setter (the success screen) and `setSubmitting` try/finally.
  - Catch → `toast.error("Couldn't confirm receipt", err?.message || '<existing fallback>')`.

- [ ] **Step 4: Remove the `submitError`-backed `po-submit-error` banner JSX.** If the page (like dispatch) also has a SEPARATE load-time `error` banner, leave that one. Read before removing.

- [ ] **Step 5: Remove `submitError`/`setSubmitError` state** with a grep guard:
  `git grep -nE "submitError|setSubmitError" frontend/src/admin/pages/ISTReceivePage.jsx` → empty after removal. Keep any load `error` state and `submitting`.

- [ ] **Step 6: Build** (per conventions).

- [ ] **Step 7: Commit**
```bash
git add frontend/src/admin/pages/ISTReceivePage.jsx
git commit -m "feat: confirm before IST receive; receive error→toast"
```

---

## Self-Review Notes

- **Spec coverage:** PO submit (Task 1), Budget create/edit + archive alert (Task 2), Adjustment create/edit (Task 3), StockTransfer create/edit (Task 4), ISTCreate submit (Task 5), ISTDispatch (Task 6), ISTReceive (Task 7). ISTManagementPage excluded per spec. ✓
- **New decision honored:** submit/create confirmed (tone `primary`) on all listed pages. ✓
- **Banner removal safety:** every state deletion is grep-guarded; CSS classes are NOT removed (shared); `submitting` kept for button state; the IST pages' separate load-error banner is explicitly preserved. ✓
- **No helper changes / no new tests:** these are integrations of already-tested helpers, gated by a clean build + grep guard + manual smoke. ✓
- **Signature consistency:** all confirms use `confirmAction({title, text?, confirmText, tone:'primary'})→Promise<boolean>`; toasts use `toast.success(title, subtitle?)` / `toast.error(title, subtitle?)` exactly as defined in Phase 1. ✓
