# Toast Feedback Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Purchase Request page's static success/error banners with the shared pop-up toast, and upgrade the shared toast to a colored-accent-bar style with an optional subtitle line.

**Architecture:** Extend the existing SweetAlert2-based `toast` util (`frontend/src/admin/utils/toast.js`) to accept an optional `subtitle` and apply a per-level CSS accent class. Restyle in `swal-toast.css`. Then point `PurchaseRequestPage.jsx` at the toast and delete the now-dead banner JSX, state, and CSS.

**Tech Stack:** React (Create React App / react-scripts), SweetAlert2, Jest + @testing-library (test runner), CSS.

---

## File Structure

- **Modify:** `frontend/src/admin/utils/toast.js` — add optional subtitle + per-level accent class.
- **Create:** `frontend/src/admin/utils/toast.test.js` — unit tests for the toast util (mock SweetAlert2).
- **Modify:** `frontend/src/admin/styles/swal-toast.css` — accent-bar styling + subtitle style.
- **Modify:** `frontend/src/admin/pages/PurchaseRequestPage.jsx` — call toast, remove banners + dead state.
- **Modify:** `frontend/src/admin/styles/po_recommendation.css` — delete dead banner classes.

**Test command (Windows PowerShell):** `npx cross-env CI=true react-scripts test <path> --watchAll=false`
(Run from `frontend/`. `cross-env CI=true` forces a single non-watch run.)

---

### Task 1: Add optional subtitle + accent class to the toast util

**Files:**
- Modify: `frontend/src/admin/utils/toast.js`
- Test: `frontend/src/admin/utils/toast.test.js`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/toast.test.js`:

```js
// Mock SweetAlert2 so toast.js's Swal.mixin(...).fire is observable.
const fireMock = jest.fn();
jest.mock('sweetalert2', () => ({
  __esModule: true,
  default: {
    mixin: jest.fn(() => ({ fire: fireMock })),
    stopTimer: jest.fn(),
    resumeTimer: jest.fn(),
  },
}));

import { toast } from './toast';

beforeEach(() => fireMock.mockClear());

describe('toast util', () => {
  test('success with subtitle passes title, escaped html subtitle, and success accent class', () => {
    toast.success('Purchase Request sent', 'PR-2026-0042 — waiting for admin approval');
    expect(fireMock).toHaveBeenCalledTimes(1);
    const arg = fireMock.mock.calls[0][0];
    expect(arg.icon).toBe('success');
    expect(arg.title).toBe('Purchase Request sent');
    expect(arg.html).toContain('PR-2026-0042 — waiting for admin approval');
    expect(arg.html).toContain('swal-toast-subtitle');
    expect(arg.customClass.popup).toBe('swal-toast swal-toast-success');
  });

  test('success without subtitle omits html (backward compatible)', () => {
    toast.success('Saved');
    const arg = fireMock.mock.calls[0][0];
    expect(arg.title).toBe('Saved');
    expect(arg.html).toBeUndefined();
    expect(arg.customClass.popup).toBe('swal-toast swal-toast-success');
  });

  test('error escapes HTML in the subtitle to prevent injection', () => {
    toast.error("Couldn't send request", '<img src=x onerror=alert(1)>');
    const arg = fireMock.mock.calls[0][0];
    expect(arg.icon).toBe('error');
    expect(arg.html).not.toContain('<img');
    expect(arg.html).toContain('&lt;img');
  });

  test('error uses a longer timer than success', () => {
    toast.error('Save failed', 'reason');
    expect(fireMock.mock.calls[0][0].timer).toBe(2500);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx cross-env CI=true react-scripts test src/admin/utils/toast.test.js --watchAll=false`
Expected: FAIL — current `toast.success` takes only a title, sets no `html`, and `customClass.popup` is `'swal-toast'` (no `swal-toast-success`).

- [ ] **Step 3: Implement the new toast util**

Replace the entire contents of `frontend/src/admin/utils/toast.js` with:

```js
import Swal from 'sweetalert2';

const Toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 1500,
  timerProgressBar: true,
  customClass: {
    popup: 'swal-toast',
  },
  didOpen: (toast) => {
    toast.addEventListener('mouseenter', Swal.stopTimer);
    toast.addEventListener('mouseleave', Swal.resumeTimer);
  },
});

const escapeHtml = (str) =>
  String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const fire = (icon, title, subtitle, timer) => {
  const options = {
    icon,
    title,
    customClass: { popup: `swal-toast swal-toast-${icon}` },
  };
  if (subtitle) {
    options.html = `<span class="swal-toast-subtitle">${escapeHtml(subtitle)}</span>`;
  }
  if (timer) options.timer = timer;
  Toast.fire(options);
};

export const toast = {
  success: (title, subtitle) => fire('success', title, subtitle, 2500),
  error:   (title, subtitle) => fire('error', title, subtitle, 2500),
  info:    (title, subtitle) => fire('info', title, subtitle, 2500),
  warning: (title, subtitle) => fire('warning', title, subtitle, 5500),
};
```

(Note: success/info timer bumped from 1500 → 2500ms so the subtitle is readable. Error stays 2500, warning 5500.)

- [ ] **Step 4: Run test to verify it passes**

Run: `npx cross-env CI=true react-scripts test src/admin/utils/toast.test.js --watchAll=false`
Expected: PASS — all 4 tests green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/utils/toast.js frontend/src/admin/utils/toast.test.js
git commit -m "feat: toast util supports subtitle and per-level accent class"
```

---

### Task 2: Restyle the toast as a colored-accent-bar card

**Files:**
- Modify: `frontend/src/admin/styles/swal-toast.css`

- [ ] **Step 1: Add accent-bar + subtitle styles**

Append to the end of `frontend/src/admin/styles/swal-toast.css`:

```css
/* Colored left accent bar per level */
.swal2-popup.swal-toast {
  border-left: 4px solid #9ca3af !important;
}
.swal2-popup.swal-toast-success { border-left-color: #059669 !important; }
.swal2-popup.swal-toast-error   { border-left-color: #dc2626 !important; }
.swal2-popup.swal-toast-warning { border-left-color: #d97706 !important; }
.swal2-popup.swal-toast-info    { border-left-color: #2563eb !important; }

/* Bold title is the SweetAlert2 default .swal2-title (already styled).
   Subtitle: small, muted, on its own line. */
.swal-toast-subtitle {
  display: block;
  font-size: 0.75rem;
  color: #6b7280;
  margin-top: 2px;
  line-height: 1.3;
}
```

- [ ] **Step 2: Verify the build still compiles**

Run: `npx cross-env CI=true react-scripts test src/admin/utils/toast.test.js --watchAll=false`
Expected: PASS (CSS has no test, but confirms nothing broke importing the util). Visual confirmation happens in Task 3.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/styles/swal-toast.css
git commit -m "style: colored accent-bar toast with subtitle line"
```

---

### Task 3: Switch Purchase Request page to the toast

**Files:**
- Modify: `frontend/src/admin/pages/PurchaseRequestPage.jsx`

- [ ] **Step 1: Import the toast util**

In `frontend/src/admin/pages/PurchaseRequestPage.jsx`, after the `useAuth` import (line 12), add:

```js
import { toast } from '../utils/toast';
```

- [ ] **Step 2: Replace validation + submit feedback with toast calls**

In `handleSubmitPR` (around lines 347–386), replace the function body's feedback calls. The new version:

```js
  const handleSubmitPR = useCallback(async () => {
    if (!selectedLocId) { toast.error("Couldn't send request", 'Please select a location.'); return; }
    if (!neededByDate)  { toast.error("Couldn't send request", 'Please set a needed-by date.'); return; }
    if (neededByDate <= new Date().toISOString().slice(0, 10)) {
      toast.error("Couldn't send request", 'Needed-by date must be a future date.'); return;
    }
    const validLines = lineItems.filter(li => li.productId && li.qty > 0);
    if (!validLines.length) {
      toast.error("Couldn't send request", 'Add at least one item with a selected product.'); return;
    }

    setSubmitting(true);
    try {
      const payload = {
        location_id:    Number(selectedLocId),
        needed_by_date: neededByDate,
        purpose:        purpose.trim() || null,
        details:        validLines.map(li => ({
          product_id:      Number(li.productId),
          quantity:        Math.max(1, parseInt(li.qty) || 1),
          estimated_price: Number(li.estPrice) || 0,
        })),
      };
      if (budget?.budget_id) payload.budget_id = budget.budget_id;

      const res = await purchaseRequestsAPI.create(payload);
      const prNum = res?.data?.pr_number || 'Purchase Request';
      toast.success('Purchase Request sent', `${prNum} — waiting for admin approval`);
      setLineItems([newLine()]);
      setPurpose('');
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to submit purchase request.';
      toast.error("Couldn't send request", msg);
    } finally {
      setSubmitting(false);
    }
  }, [selectedLocId, neededByDate, purpose, lineItems, budget]);
```

(This removes all `setSubmitError`/`setSubmitSuccess`/`setCreatedPRNum` calls.)

- [ ] **Step 3: Remove the success banner JSX**

Delete the success banner block in `renderCreateTab` (lines 493–503):

```jsx
      {submitSuccess && (
        <div className="po-success-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
          </svg>
          <strong>{createdPRNum}</strong> submitted! Waiting for Admin approval.
          <button type="button" className="po-success-close" onClick={() => setSubmitSuccess(false)} aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      )}

```

(Delete the whole block including the trailing blank line, leaving `<div className="pr-create-form-layout">` directly followed by the `{/* Request details card */}` comment.)

- [ ] **Step 4: Remove the error banner JSX**

Delete the error banner line (line 758):

```jsx
      {submitError && <div className="po-submit-error">{submitError}</div>}
```

- [ ] **Step 5: Remove the now-dead state declarations**

Delete these three lines (originally 140–142):

```js
  const [submitError, setSubmitError] = useState('');
  const [submitSuccess, setSubmitSuccess] = useState(false);
  const [createdPRNum,  setCreatedPRNum]  = useState('');
```

Keep `const [submitting, setSubmitting] = useState(false);` — the submit button still uses it.

- [ ] **Step 6: Verify no orphaned references remain**

Run (from repo root): `git grep -nE "submitError|submitSuccess|createdPRNum" frontend/src/admin/pages/PurchaseRequestPage.jsx`
Expected: **no output** (all references removed).

- [ ] **Step 7: Build to confirm it compiles**

Run (from `frontend/`): `npx cross-env CI=true react-scripts build`
Expected: "Compiled successfully" (warnings about unused vars elsewhere are fine; there must be NO error and no unused-var warning for `submitError`/`submitSuccess`/`createdPRNum`).

- [ ] **Step 8: Manual verification**

Start the app (`npm start`), open the Purchase Request → Create tab:
- Submit with no location selected → red accent-bar toast top-right: "Couldn't send request / Please select a location.", auto-dismisses, form does NOT shift down.
- Submit a valid request → green accent-bar toast: "Purchase Request sent / PR-XXXX — waiting for admin approval", form resets, no inline banner.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/admin/pages/PurchaseRequestPage.jsx
git commit -m "feat: use pop-up toast for Purchase Request submit feedback"
```

---

### Task 4: Delete dead banner CSS

**Files:**
- Modify: `frontend/src/admin/styles/po_recommendation.css`

- [ ] **Step 1: Confirm the classes are unreferenced**

Run (from repo root): `git grep -nE "po-success-banner|po-success-close|po-submit-error" frontend/src`
Expected: matches ONLY in `po_recommendation.css` (no `.jsx` references). If any `.jsx` match remains, stop and fix Task 3.

- [ ] **Step 2: Remove the rules**

In `frontend/src/admin/styles/po_recommendation.css`, delete the `.po-success-banner` rule (lines ~923–935), the `.po-success-banner svg` rule (~937–940), the `.po-success-close` rule (~942–950), and the `/* Submit error */` comment + `.po-submit-error` rule (~952–961). Leave the surrounding `/* ── Page Header ── */` section intact.

- [ ] **Step 3: Confirm nothing references the deleted classes**

Run: `git grep -nE "po-success-banner|po-success-close|po-submit-error" frontend/src`
Expected: **no output**.

- [ ] **Step 4: Build to confirm**

Run (from `frontend/`): `npx cross-env CI=true react-scripts build`
Expected: "Compiled successfully".

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/styles/po_recommendation.css
git commit -m "chore: remove dead Purchase Request banner CSS"
```

---

## Self-Review Notes

- **Spec coverage:** Subtitle API (Task 1), accent-bar style (Task 2), PR banner→toast swap + copy conventions (Task 3), CSS cleanup (Task 3 JSX + Task 4 CSS). ✓
- **XSS:** error reason flows into the subtitle; Task 1 escapes it via `escapeHtml`. ✓
- **Backward compatibility:** existing single-arg `toast.*` callers across the app keep working — `subtitle` is optional and `html` is omitted when absent (covered by a test). ✓
- **Naming consistency:** `fire(icon, title, subtitle, timer)`, class `swal-toast-${icon}`, and `.swal-toast-subtitle` are used identically in util, CSS, and tests. ✓
- **Out of scope (per spec):** approve/reject `alert(...)` calls and other pages' static banners are intentionally not changed here.
