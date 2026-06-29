# Confirm Dialogs + Action Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a shared, good-looking confirmation dialog before approve/reject/cancel/delete actions across the admin app, and replace native `window.confirm()`/`alert()` in the touched handlers with the dialog and the toast.

**Architecture:** A new `frontend/src/admin/utils/confirm.js` wraps SweetAlert2 (same library as the toast) exposing two async functions — `confirmAction` (yes/no) and `confirmWithReason` (adds a textarea). Styling is appended to the existing `swal-toast.css`. Callers `await` the helper and return early on cancel; failures use `toast.error`, successes use `toast.success`.

**Tech Stack:** React (Create React App / react-scripts), SweetAlert2, Jest + @testing-library, CSS.

---

## File Structure

- **Create:** `frontend/src/admin/utils/confirm.js` — the shared confirm dialog helper (two functions).
- **Create:** `frontend/src/admin/utils/confirm.test.js` — unit tests (mock SweetAlert2).
- **Modify:** `frontend/src/admin/styles/swal-toast.css` — append confirm-dialog styling.
- **Modify (rollout):** `PurchaseRequestPage.jsx`, `TransferRequestPage.jsx`, `ArchiveManagement.jsx`, `ArchiveSettingsPage.jsx`, `DeliveryReceiptManagement.jsx`, `BarcodeAdjustmentPage.jsx`, `IssuanceScanPage.jsx`, `UserManagement.jsx`, `ReceivingManagement.jsx`, `SupplierNetwork.jsx`, `PurchaseReturnManagement.jsx`, `ProfitLossManagement.jsx` (all under `frontend/src/admin/pages/`).
- **Do NOT touch:** `frontend/src/admin/components/ArchiveButton.jsx` (already has a good inline confirm).

**Test command (Windows PowerShell, run from `frontend/`):**
`npx cross-env CI=true react-scripts test <path> --watchAll=false`

**Build check (from `frontend/`):** `npx react-scripts build` → expect "Compiled successfully" (a `CI=true` build fails on PRE-EXISTING warnings in unrelated files — do not use CI=true to gate the build).

---

## Conversion Recipe (reference for Tasks 2–5)

**A. Native `window.confirm` → `confirmAction`:**
- Make the enclosing handler `async` if it is not already.
- `if (!window.confirm('MSG')) return;` becomes:
  ```js
  if (!(await confirmAction({ title: 'TITLE', text: 'DETAIL', confirmText: 'CONFIRM_VERB', tone: 'danger' }))) return;
  ```
  Split the original `'MSG'` into a short `title` (the question) and optional `text` (the detail). Keep the original meaning.

**B. Native `alert(err?.message || '...')` in a touched handler's catch → toast:**
- `alert(err?.message || 'X failed.');` becomes `toast.error("Couldn't <verb>", err?.message || 'X failed.');`

**C. Success feedback:** after a successful action in a touched handler, add `toast.success('<Past-tense outcome>')` if no success feedback already exists. Do not duplicate an existing one.

**D. Imports:** add at top of each touched file (only the ones used):
```js
import { confirmAction, confirmWithReason } from '../utils/confirm';
import { toast } from '../utils/toast';
```
(If `toast` is already imported, don't duplicate.)

**E. After edits in any rollout file:** run `npx react-scripts build` from `frontend/` and confirm "Compiled successfully" with no new unused-var warnings for anything you removed.

---

### Task 1: Build the confirm dialog helper

**Files:**
- Create: `frontend/src/admin/utils/confirm.js`
- Test: `frontend/src/admin/utils/confirm.test.js`
- Modify: `frontend/src/admin/styles/swal-toast.css`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/confirm.test.js`:

```js
// Mock SweetAlert2. The jest.mock factory is hoisted above imports, so we
// define the spies inside it and expose them on the mock default export.
jest.mock('sweetalert2', () => {
  const mockFire = jest.fn();
  const mockShowValidationMessage = jest.fn();
  return {
    __esModule: true,
    default: {
      mixin: jest.fn(() => ({ fire: mockFire })),
      showValidationMessage: mockShowValidationMessage,
      __mockFire: mockFire,
      __mockShowValidationMessage: mockShowValidationMessage,
    },
  };
});

import Swal from 'sweetalert2';
import { confirmAction, confirmWithReason } from './confirm';

const mockFire = Swal.__mockFire;
const mockShowValidationMessage = Swal.__mockShowValidationMessage;

beforeEach(() => {
  mockFire.mockReset();
  mockShowValidationMessage.mockReset();
});

describe('confirmAction', () => {
  test('resolves true when confirmed', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true });
    await expect(confirmAction({ title: 'Approve?' })).resolves.toBe(true);
  });

  test('resolves false when dismissed/cancelled', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false, dismiss: 'cancel' });
    await expect(confirmAction({ title: 'Approve?' })).resolves.toBe(false);
  });

  test('primary tone uses question icon + primary button class', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true });
    await confirmAction({ title: 'Approve?', confirmText: 'Approve', tone: 'primary' });
    const opts = mockFire.mock.calls[0][0];
    expect(opts.icon).toBe('question');
    expect(opts.confirmButtonText).toBe('Approve');
    expect(opts.customClass.confirmButton).toContain('swal-confirm-primary');
  });

  test('danger tone (default) uses warning icon + danger button class', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true });
    await confirmAction({ title: 'Delete?' });
    const opts = mockFire.mock.calls[0][0];
    expect(opts.icon).toBe('warning');
    expect(opts.customClass.confirmButton).toContain('swal-confirm-danger');
  });
});

describe('confirmWithReason', () => {
  test('returns confirmed + trimmed reason on confirm', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true, value: '  too expensive  ' });
    await expect(
      confirmWithReason({ title: 'Reject?' })
    ).resolves.toEqual({ confirmed: true, reason: 'too expensive' });
  });

  test('returns not-confirmed + empty reason on dismiss', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false, dismiss: 'cancel' });
    await expect(
      confirmWithReason({ title: 'Reject?' })
    ).resolves.toEqual({ confirmed: false, reason: '' });
  });

  test('renders a textarea input', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false });
    await confirmWithReason({ title: 'Reject?', reasonLabel: 'Why' });
    const opts = mockFire.mock.calls[0][0];
    expect(opts.input).toBe('textarea');
    expect(opts.inputLabel).toBe('Why');
  });

  test('preConfirm blocks empty reason when reasonRequired', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false });
    await confirmWithReason({ title: 'Reject?', reasonRequired: true, reasonLabel: 'Reason' });
    const { preConfirm } = mockFire.mock.calls[0][0];
    expect(preConfirm('   ')).toBe(false);
    expect(mockShowValidationMessage).toHaveBeenCalledWith('Reason is required');
  });

  test('preConfirm accepts a non-empty reason when reasonRequired', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false });
    await confirmWithReason({ title: 'Reject?', reasonRequired: true });
    const { preConfirm } = mockFire.mock.calls[0][0];
    expect(preConfirm('a real reason')).toBe('a real reason');
    expect(mockShowValidationMessage).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx cross-env CI=true react-scripts test src/admin/utils/confirm.test.js --watchAll=false`
Expected: FAIL — `./confirm` does not exist yet ("Cannot find module './confirm'").

- [ ] **Step 3: Implement the helper**

Create `frontend/src/admin/utils/confirm.js`:

```js
import Swal from 'sweetalert2';

const ConfirmDialog = Swal.mixin({
  showCancelButton: true,
  reverseButtons: true,
  focusCancel: false,
  buttonsStyling: false,
});

const toneIcon = (tone) => (tone === 'primary' ? 'question' : 'warning');
const toneClass = (tone) => (tone === 'primary' ? 'swal-confirm-primary' : 'swal-confirm-danger');

const baseClasses = (tone) => ({
  popup: `swal-confirm ${toneClass(tone)}`,
  title: 'swal-confirm-title',
  htmlContainer: 'swal-confirm-text',
  confirmButton: `swal-confirm-btn ${toneClass(tone)}`,
  cancelButton: 'swal-cancel-btn',
  actions: 'swal-confirm-actions',
});

export const confirmAction = async ({
  title,
  text = '',
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  tone = 'danger',
} = {}) => {
  const result = await ConfirmDialog.fire({
    title,
    text,
    icon: toneIcon(tone),
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    customClass: baseClasses(tone),
  });
  return result.isConfirmed === true;
};

export const confirmWithReason = async ({
  title,
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  tone = 'danger',
  reasonRequired = false,
  reasonLabel = 'Reason',
  reasonPlaceholder = '',
} = {}) => {
  const result = await ConfirmDialog.fire({
    title,
    icon: toneIcon(tone),
    input: 'textarea',
    inputLabel: reasonLabel,
    inputPlaceholder: reasonPlaceholder,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    customClass: { ...baseClasses(tone), input: 'swal-confirm-input' },
    preConfirm: (value) => {
      if (reasonRequired && !String(value || '').trim()) {
        Swal.showValidationMessage(`${reasonLabel} is required`);
        return false;
      }
      return value;
    },
  });
  return {
    confirmed: result.isConfirmed === true,
    reason: result.isConfirmed ? String(result.value || '').trim() : '',
  };
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx cross-env CI=true react-scripts test src/admin/utils/confirm.test.js --watchAll=false`
Expected: PASS — all tests green.

- [ ] **Step 5: Append confirm-dialog styling**

Append to the END of `frontend/src/admin/styles/swal-toast.css`:

```css
/* ── Confirm dialog (shared confirm.js) ── */
.swal2-popup.swal-confirm {
  border-radius: 0.75rem !important;
  padding: 1.5rem !important;
}
.swal-confirm-title { font-size: 1.125rem !important; color: #111827 !important; }
.swal-confirm-text  { font-size: 0.875rem !important; color: #4b5563 !important; }
.swal-confirm-actions { gap: 0.5rem !important; }
.swal-confirm-btn {
  border: none !important;
  border-radius: 0.5rem !important;
  padding: 0.5rem 1.25rem !important;
  font-size: 0.875rem !important;
  font-weight: 600 !important;
  color: #fff !important;
  cursor: pointer !important;
}
.swal-confirm-btn.swal-confirm-danger  { background: #dc2626 !important; }
.swal-confirm-btn.swal-confirm-primary { background: #2563eb !important; }
.swal-cancel-btn {
  border: 1px solid #d1d5db !important;
  border-radius: 0.5rem !important;
  padding: 0.5rem 1.25rem !important;
  font-size: 0.875rem !important;
  font-weight: 600 !important;
  background: #fff !important;
  color: #374151 !important;
  cursor: pointer !important;
}
.swal-confirm-input {
  font-size: 0.875rem !important;
}
```

- [ ] **Step 6: Re-run the test (CSS shouldn't break anything)**

Run: `npx cross-env CI=true react-scripts test src/admin/utils/confirm.test.js --watchAll=false`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/utils/confirm.js frontend/src/admin/utils/confirm.test.js frontend/src/admin/styles/swal-toast.css
git commit -m "feat: shared confirm dialog helper (confirmAction + confirmWithReason)"
```

---

### Task 2: Purchase Request — approve confirm + reject dialog + alert→toast

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`

`toast` is already imported in this file (from the prior toast work). Add only the confirm import.

- [ ] **Step 1: Add the confirm import**

After the existing `import { toast } from '../utils/toast';` line, add:

```js
import { confirmAction, confirmWithReason } from '../utils/confirm';
```

- [ ] **Step 2: Add a confirm to `handleApprove`**

Replace the `handleApprove` callback (currently around lines 393–401) with:

```js
  const handleApprove = useCallback(async (prId) => {
    if (!(await confirmAction({
      title: 'Approve this purchase request?',
      confirmText: 'Approve',
      tone: 'primary',
    }))) return;
    setActionLoading(p => ({ ...p, [prId]: 'approving' }));
    try {
      await purchaseRequestsAPI.approve(prId);
      setPendingPRs(p => p.filter(pr => pr.pr_id !== prId));
      if (selectedPR?.pr_id === prId) setSelectedPR(p => p ? { ...p, status_id: PR_STATUS.APPROVED } : null);
      toast.success('Request approved');
    } catch (err) { toast.error("Couldn't approve request", err?.message || 'Approval failed.'); }
    finally { setActionLoading(p => { const n = { ...p }; delete n[prId]; return n; }); }
  }, [selectedPR]);
```

- [ ] **Step 3: Replace the reject flow with `confirmWithReason`**

Replace BOTH `openRejectModal` (currently ~403–404) and `handleReject` (currently ~406–419) with this single new `handleReject` (takes `prId`):

```js
  const handleReject = useCallback(async (prId) => {
    const { confirmed, reason } = await confirmWithReason({
      title: 'Reject this purchase request?',
      confirmText: 'Reject',
      tone: 'danger',
      reasonRequired: true,
      reasonLabel: 'Reason for rejection',
      reasonPlaceholder: 'Explain why this purchase request is being rejected…',
    });
    if (!confirmed) return;
    setActionLoading(p => ({ ...p, [prId]: 'rejecting' }));
    try {
      await purchaseRequestsAPI.reject(prId, { rejection_reason: reason });
      setPendingPRs(p => p.filter(pr => pr.pr_id !== prId));
      if (selectedPR?.pr_id === prId)
        setSelectedPR(p => p ? { ...p, status_id: PR_STATUS.REJECTED, rejection_reason: reason } : null);
      toast.success('Request rejected');
    } catch (err) {
      toast.error("Couldn't reject request", err?.message || 'Rejection failed.');
    } finally { setActionLoading(p => { const n = { ...p }; delete n[prId]; return n; }); }
  }, [selectedPR]);
```

- [ ] **Step 4: Remove the `rejectModal` state**

Delete this line (currently ~153):

```js
  const [rejectModal,  setRejectModal]  = useState({ open: false, prId: null, reason: '', submitting: false });
```

- [ ] **Step 5: Remove the reject modal render function and its call**

Delete the entire `renderRejectModal` function (currently ~1224–1254) and the `{renderRejectModal()}` line in the main render (currently ~1331).

- [ ] **Step 6: Rewire the two reject buttons**

- Currently ~998: `onClick={() => openRejectModal(pr.pr_id)}` → `onClick={() => handleReject(pr.pr_id)}`
- Currently ~1209: `onClick={() => { setSelectedPR(null); openRejectModal(pr.pr_id); }}` → `onClick={() => { setSelectedPR(null); handleReject(pr.pr_id); }}`

- [ ] **Step 7: Check whether `Modal` import is now unused**

Run from repo root: `git grep -nE "<Modal|Modal\b" frontend/src/admin/pages/PurchaseRequestPage.jsx`
If `Modal` is no longer used anywhere in the file, remove its import (`import Modal from '../components/Modal';`). If it is still used, leave the import.

- [ ] **Step 8: Verify no orphaned references**

Run from repo root: `git grep -nE "rejectModal|openRejectModal|setRejectModal|renderRejectModal" frontend/src/admin/pages/PurchaseRequestPage.jsx`
Expected: NO output.

- [ ] **Step 9: Build**

Run from `frontend/`: `npx react-scripts build`
Expected: "Compiled successfully", no new unused-var warnings for the removed identifiers.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat: confirm dialog for PR approve/reject; replace reject modal and alerts"
```

---

### Task 3: Transfer Request — approve confirm + reject dialog + alert→toast

**Files:**
- Modify: `frontend/src/admin/pages/TransferRequestPage.jsx`

- [ ] **Step 1: Add imports (per recipe D)**

Add at the top with the other imports (add `toast` only if not already present):

```js
import { confirmAction, confirmWithReason } from '../utils/confirm';
import { toast } from '../utils/toast';
```

- [ ] **Step 2: Add a confirm to `handleApprove`**

Replace the `handleApprove` callback (currently ~175–187) with:

```js
  const handleApprove = useCallback(async (id) => {
    if (!(await confirmAction({
      title: 'Approve this transfer request?',
      confirmText: 'Approve',
      tone: 'primary',
    }))) return;
    setActionLoading(p => ({ ...p, [id]: 'approving' }));
    try {
      const quantities = Object.entries(approvedQtys).map(([detailId, qty]) => ({
        transfer_detail_id: Number(detailId),
        quantity_approved:  Math.max(1, parseInt(qty) || 1),
      }));
      await transferRequestsAPI.approve(id, { quantities });
      setPendingReqs(p => p.filter(r => r.transfer_id !== id));
      closeDetail();
      toast.success('Transfer approved');
    } catch (err) { toast.error("Couldn't approve transfer", err?.message || 'Approval failed.'); }
    finally { setActionLoading(p => { const n = { ...p }; delete n[id]; return n; }); }
  }, [approvedQtys, closeDetail]);
```

- [ ] **Step 3: Replace the reject flow with `confirmWithReason`**

Replace the `rejectModal` state + `openReject` + `handleReject` (currently ~190–204) with this single new `handleReject` (takes `id`):

```js
  const handleReject = useCallback(async (id) => {
    const { confirmed, reason } = await confirmWithReason({
      title: 'Reject this transfer request?',
      confirmText: 'Reject',
      tone: 'danger',
      reasonRequired: true,
      reasonLabel: 'Reason for rejection',
    });
    if (!confirmed) return;
    setActionLoading(p => ({ ...p, [id]: 'rejecting' }));
    try {
      await transferRequestsAPI.reject(id, { rejection_reason: reason });
      setPendingReqs(p => p.filter(r => r.transfer_id !== id));
      closeDetail();
      toast.success('Transfer rejected');
    } catch (err) {
      toast.error("Couldn't reject transfer", err?.message || 'Rejection failed.');
    } finally { setActionLoading(p => { const n = { ...p }; delete n[id]; return n; }); }
  }, [closeDetail]);
```

- [ ] **Step 4: Find and remove the transfer reject modal JSX + rewire its trigger**

Run from repo root: `git grep -nE "openReject|rejectModal|setRejectModal" frontend/src/admin/pages/TransferRequestPage.jsx`
For each result:
- Any reject-trigger button calling `openReject(<x>)` → change to `handleReject(<x>)`.
- Delete the reject `<Modal ...>` JSX block (the one bound to `rejectModal.open`) and its render/usage.
- Ensure the `rejectModal` state declaration and `openReject` are gone.

- [ ] **Step 5: Check whether `Modal` import is now unused**

Run: `git grep -nE "<Modal|Modal\b" frontend/src/admin/pages/TransferRequestPage.jsx`
If unused, remove the `Modal` import; otherwise leave it.

- [ ] **Step 6: Verify no orphaned references**

Run: `git grep -nE "rejectModal|openReject|setRejectModal" frontend/src/admin/pages/TransferRequestPage.jsx`
Expected: NO output.

- [ ] **Step 7: Build**

Run from `frontend/`: `npx react-scripts build`
Expected: "Compiled successfully", no new unused-var warnings.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/TransferRequestPage.jsx
git commit -m "feat: confirm dialog for transfer approve/reject; replace reject modal and alerts"
```

---

### Task 4: Archive pages — multi-step `window.confirm` conversions

**Files:**
- Modify: `frontend/src/admin/pages/ArchiveManagement.jsx`
- Modify: `frontend/src/admin/pages/ArchiveSettingsPage.jsx`

Follow Conversion Recipe A/B/C/D/E. **Keep every two-step confirmation as two steps** (two sequential `confirmAction` awaits) — do not collapse them into one.

- [ ] **Step 1: Add imports to both files (recipe D)**

```js
import { confirmAction } from '../utils/confirm';
import { toast } from '../utils/toast';
```

- [ ] **Step 2: Convert `ArchiveManagement.jsx` sites**

Convert each, making the enclosing handler `async`:
- `:99` `if (!window.confirm('Restore this record? It will become active again.')) return;` →
  ```js
  if (!(await confirmAction({ title: 'Restore this record?', text: 'It will become active again.', confirmText: 'Restore', tone: 'danger' }))) return;
  ```
- `:123` + `:126` (two-step permanent delete) — keep BOTH as sequential awaits, preserving the original two messages as the two dialogs' title/text. The second uses `confirmText: 'Delete permanently'`.
- `:151` `if (!window.confirm(\`Restore all ${totalItems} archived ${activeModule} records?\`)) return;` →
  ```js
  if (!(await confirmAction({ title: `Restore all ${totalItems} archived ${activeModule} records?`, confirmText: 'Restore all', tone: 'danger' }))) return;
  ```
- In each of these handlers, apply recipe B (alert→toast.error) and recipe C (toast.success on success) if such calls exist.

- [ ] **Step 3: Convert `ArchiveSettingsPage.jsx` sites**

- `:334` `if (!window.confirm('This will archive records based on current settings. Continue?')) return;` →
  ```js
  if (!(await confirmAction({ title: 'Archive records now?', text: 'This will archive records based on current settings.', confirmText: 'Archive', tone: 'danger' }))) return;
  ```
- `:378` + `:379` (two-step permanent delete) — keep BOTH sequential awaits, preserving the two original messages; second uses `confirmText: 'Delete permanently'`.
- Apply recipe B/C in these handlers where alert/success exist.

- [ ] **Step 4: Verify no native confirm remains in these two files**

Run: `git grep -nE "window\.confirm" frontend/src/admin/pages/ArchiveManagement.jsx frontend/src/admin/pages/ArchiveSettingsPage.jsx`
Expected: NO output.

- [ ] **Step 5: Build**

Run from `frontend/`: `npx react-scripts build`
Expected: "Compiled successfully".

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/ArchiveManagement.jsx frontend/src/admin/pages/ArchiveSettingsPage.jsx
git commit -m "feat: confirm dialogs for archive restore/delete actions"
```

---

### Task 5: Remaining `window.confirm` pages

**Files (one handler each):**
- `frontend/src/admin/pages/DeliveryReceiptManagement.jsx:84` (delete delivery receipt)
- `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx:804` (permanent inventory modification)
- `frontend/src/admin/pages/IssuanceScanPage.jsx:913` (remove product from issuance) and `:963` (the `msg` variable)
- `frontend/src/admin/pages/UserManagement.jsx:166` (activate/deactivate user)
- `frontend/src/admin/pages/ReceivingManagement.jsx:150` (delete receiving record)
- `frontend/src/admin/pages/SupplierNetwork.jsx:289` (remove product from supplier)
- `frontend/src/admin/pages/PurchaseReturnManagement.jsx:111` (delete purchase return)
- `frontend/src/admin/pages/ProfitLossManagement.jsx:176` (delete profit/loss record)

Apply Conversion Recipe A (make handler `async`, `window.confirm` → `await confirmAction({ ..., tone: 'danger' })`), plus B (alert→toast.error) and C (toast.success on success) within each touched handler. Add imports per recipe D to each file.

Concrete mappings:
- DeliveryReceipt `:84`: `confirmAction({ title: 'Delete this delivery receipt?', confirmText: 'Delete', tone: 'danger' })`
- BarcodeAdjustment `:804`: `confirmAction({ title: 'Modify inventory records?', text: 'This will permanently modify inventory records.', confirmText: 'Confirm', tone: 'danger' })`
- IssuanceScan `:913`: `confirmAction({ title: \`Remove ${productName} from this issuance?\`, confirmText: 'Remove', tone: 'danger' })`
- IssuanceScan `:963`: the existing `msg` string is the dialog title — `confirmAction({ title: msg, confirmText: 'Confirm', tone: 'danger' })`
- UserManagement `:166`: `confirmAction({ title: \`Are you sure you want to ${action} ${getUserName(user)}?\`, confirmText: 'Confirm', tone: 'danger' })`
- Receiving `:150`: `confirmAction({ title: 'Delete this receiving record?', confirmText: 'Delete', tone: 'danger' })`
- SupplierNetwork `:289`: `confirmAction({ title: 'Remove this product from the supplier?', confirmText: 'Remove', tone: 'danger' })`
- PurchaseReturn `:111`: `confirmAction({ title: 'Delete this purchase return?', confirmText: 'Delete', tone: 'danger' })`
- ProfitLoss `:176`: `confirmAction({ title: 'Delete this profit/loss record?', confirmText: 'Delete', tone: 'danger' })`

- [ ] **Step 1: Convert each file** per the mappings above (and recipe B/C/D within each touched handler).

- [ ] **Step 2: Verify no native confirm remains across the rollout**

Run from repo root: `git grep -nE "window\.confirm" frontend/src/admin/pages`
Expected: NO output (all converted). If any remain that were NOT in scope, leave them and note it.

- [ ] **Step 3: Build**

Run from `frontend/`: `npx react-scripts build`
Expected: "Compiled successfully".

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/DeliveryReceiptManagement.jsx frontend/src/admin/pages/BarcodeAdjustmentPage.jsx frontend/src/admin/pages/IssuanceScanPage.jsx frontend/src/admin/pages/UserManagement.jsx frontend/src/admin/pages/ReceivingManagement.jsx frontend/src/admin/pages/SupplierNetwork.jsx frontend/src/admin/pages/PurchaseReturnManagement.jsx frontend/src/admin/pages/ProfitLossManagement.jsx
git commit -m "feat: replace native window.confirm with shared confirm dialog across action pages"
```

---

## Self-Review Notes

- **Spec coverage:** Helper with both functions + tone styling + reason validation (Task 1). Approve confirms added (Tasks 2–3). Reject reason dialogs replacing custom modals (Tasks 2–3). `window.confirm` conversions incl. two-step deletes preserved (Tasks 4–5). alert→toast + success toast via recipe B/C (Tasks 2–5). `ArchiveButton` untouched (explicit in File Structure + non-goals). Submit/create confirms excluded (per spec). ✓
- **Signature consistency:** `confirmAction({title,text,confirmText,cancelText,tone})→Promise<boolean>` and `confirmWithReason({title,confirmText,cancelText,tone,reasonRequired,reasonLabel,reasonPlaceholder})→Promise<{confirmed,reason}>` used identically in helper, tests, and all call sites. ✓
- **Orphan guards:** grep guards before deleting reject-modal state in Tasks 2 (Step 8) and 3 (Step 6), and `Modal`-import-unused checks. Mirrors the toast task's CSS-guard lesson (don't assume something is dead — verify). ✓
- **Build gating:** uses plain `react-scripts build` (not `CI=true`) because the repo has pre-existing warnings-as-errors; documented in File Structure. ✓
- **Risk — double submit:** approve/reject handlers set `actionLoading` while awaiting; the dialog gates entry, so no extra busy flag needed.
