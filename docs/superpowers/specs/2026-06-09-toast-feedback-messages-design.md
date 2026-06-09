# Toast Feedback Messages — Design Spec

**Date:** 2026-06-09
**Status:** Approved for planning

## Problem

Creating things (e.g. submitting a Purchase Request) currently shows feedback as a
**static inline banner** that appears at the top of the form, pushes content down, and
stays until manually dismissed. It feels "lame" and inconsistent.

Meanwhile the app already has a shared **toast** utility (`frontend/src/admin/utils/toast.js`,
built on SweetAlert2) that slides in from the top-right and auto-disappears — but the
Purchase Request page isn't using it. We want a feedback message that "pops up then
disappears," and we want the shared toast to look better everywhere.

## Goals

1. Replace the Purchase Request page's static success/error banners with the shared toast.
2. Upgrade the shared toast's appearance to a **colored-accent-bar** style.
3. Support a **title + optional subtitle** so the outcome reads first and the reference
   number / next-step reads second.
4. Establish consistent success/fail copy conventions.

## Non-Goals

- Migrating every other page off static banners in this pass. (We touch the PR page; other
  pages that already call `toast.*` automatically get the new look. A broader banner sweep
  is out of scope for this spec.)
- Changing backend responses or validation logic.

## Visual Design

**Style:** White card, thick colored left accent bar (green = success, red = error,
amber = warning, blue = info), icon, **bold title**, small muted subtitle.

```
┌─┬───────────────────────────┐
│ │ ✓  Purchase Request sent  │
│█│    PR-2026-0042 · awaiting │
│ │    admin approval         │
└─┴───────────────────────────┘
```

- **Position:** top-right (existing `top-end`).
- **Behavior:** slides in, auto-dismisses (~2500ms success/info, longer for error/warning),
  timer progress bar, pause-on-hover (already implemented).
- Implemented via CSS in `frontend/src/admin/styles/swal-toast.css` (extend existing file).
  Accent color per icon type via SweetAlert2's icon class or a custom class per level.

## API Change — shared toast util

Extend `frontend/src/admin/utils/toast.js` so each level accepts an optional subtitle:

```js
toast.success(title, subtitle?)
toast.error(title, subtitle?)
toast.info(title, subtitle?)
toast.warning(title, subtitle?)
```

- `title` → SweetAlert2 `title` (bold).
- `subtitle` → SweetAlert2 `html`/`text` (small, muted). Optional; when omitted, renders
  title only (backward compatible with existing single-arg callers across the app).
- Timers unchanged from current values (success/info 1500–2500ms, error 2500ms,
  warning 5500ms) — confirm/tune during implementation.

## Purchase Request page changes

File: `frontend/src/admin/pages/PurchaseRequestPage.jsx`

1. **Success:** on successful submit, call
   `toast.success('Purchase Request sent', '${createdPRNum} — waiting for admin approval')`
   instead of setting `submitSuccess`.
2. **Error:** on submit failure, call
   `toast.error("Couldn't send request", <reason>)` instead of setting `submitError`,
   where `<reason>` is the existing computed message (validation errors joined, or
   `err.message`, or fallback).
3. **Remove** the `po-success-banner` JSX block and the `po-submit-error` JSX block, plus
   the now-unused `submitSuccess` / `submitError` state if nothing else reads them.
4. **CSS cleanup:** remove `.po-success-banner`, `.po-success-close`, `.po-submit-error`
   rules from `frontend/src/admin/styles/po_recommendation.css` once unreferenced.

> Note: the approve/reject actions in this file currently use native `alert(...)`. Optional:
> switch those to `toast.error(...)` for consistency. Decide during implementation — low risk.

## Copy conventions

- **Title = what happened**, human and confident ("Purchase Request sent", not
  "Submission successful").
- **Errors blame the system, not the user** ("Couldn't send request"), with the real
  reason in the subtitle.
- **Reference number goes in the subtitle**, so the eye catches the outcome first.
- Tone: clear/professional (not playful, not stiffly formal).

Reference copy for common create actions (apply as those pages are touched in future):

| Action | Success (title · subtitle) | Fail (title · subtitle) |
|---|---|---|
| Purchase Request | Purchase Request sent · `{num} — waiting for admin approval` | Couldn't send request · reason |
| Purchase Order | Purchase Order created · `{num} saved` | Couldn't create PO · reason |
| Transfer / IST | Transfer requested · `{num} — pending dispatch` | Transfer failed · reason |
| Item | Item added · "Now in your catalog" | Couldn't save item · reason |
| Generic save | Saved · "Your changes are in" | Save failed · reason |

## Testing / Verification

- Submit a valid Purchase Request → green accent-bar toast appears top-right with PR number,
  auto-dismisses, no inline banner pushes the form down.
- Force a submit error (e.g. missing required field / server error) → red accent-bar toast
  with the real reason; no inline red banner.
- Confirm existing single-arg `toast.*` callers elsewhere still render correctly (title only).
- Visual check of accent colors for success/error/info/warning.

## Risks

- Removing `submitSuccess`/`submitError` state: confirm no other JSX/logic references them
  before deleting.
- CSS rule removal: confirm classes aren't reused on another page before deleting.
