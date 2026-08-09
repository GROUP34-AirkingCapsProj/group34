# Incident Guided Flows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the incident list the first thing on the Report Incident page, and replace the 703-line one-size-fits-all `IncidentFileModal` with a guided wizard whose third step is chosen by what the user is reporting.

**Architecture:** One `IncidentFlow` step machine owns all state and the submit payload. Steps are controlled presentational components that receive values and setters — they hold nothing themselves, so Back never loses data. Step 3 is picked from a map keyed on `reasonMeta(reason).outcome`; the `none` outcome has no step 3, making `other` a three-step flow. The pure parts (step sequencing, the UTC conversion, the pack arithmetic) live in a plain module with unit tests.

**Tech Stack:** React 18 (CRA), Jest + `@testing-library/react` via `react-scripts test`, the existing `ak-*` design-system classes. **No backend change of any kind.**

**Spec:** `docs/superpowers/specs/2026-08-10-incident-guided-flows-design.md`

---

## Before you start

Read this section. Several of these have already cost a full debugging cycle on this feature.

- **Do not touch the backend.** No migration, no controller, no service. The schema is a SQL dump, not a migration history — a bare `php artisan migrate` fails.
- **~20 files are already dirty** in the working tree from unrelated earlier work, including `airkingv5.sql`. **Stage only the files each task names.** Never `git add -A` or `git add .`.
- **PowerShell here-strings break `git commit -m`.** Use `git commit -F - <<'EOF' … EOF` from the Bash tool.
- Frontend commands run from `frontend/`. Lint one file with `npx eslint src/admin/...`. The full build is `npm run build`.
- Tests: `npm test -- --watchAll=false --testPathPattern=incidentFlow`.
- `exhaustive-deps` cannot follow member expressions. If you need `photo.clear` in a dep array, destructure it into a plain `const { clear: clearPhoto } = photo` first — the existing modal does exactly this at line 78 and explains why.
- The build compiles code ESLint rejects. **Both** must be run.

## File structure

| File | Status | Responsibility |
|---|---|---|
| `frontend/src/admin/utils/incidentFlow.js` | create | `stepsFor`, `toBackendInstant`, `totalPiecesFrom`, `STEP_KEYS` |
| `frontend/src/admin/utils/incidentFlow.test.js` | create | unit tests for the above |
| `frontend/src/admin/components/incident/IncidentList.jsx` | create | list + filters, extracted from the page |
| `frontend/src/admin/components/incident/IncidentFlow.jsx` | create | the step machine |
| `frontend/src/admin/components/incident/steps/WhatHappenedStep.jsx` | create | reason + occurred-at |
| `frontend/src/admin/components/incident/steps/WhereAndWhatStep.jsx` | create | location + scan/type/pick |
| `frontend/src/admin/components/incident/steps/WriteOffStep.jsx` | create | quantity + batch |
| `frontend/src/admin/components/incident/steps/RetypeStep.jsx` | create | batch (required) |
| `frontend/src/admin/components/incident/steps/RelocateStep.jsx` | create | batch + actual zone |
| `frontend/src/admin/components/incident/steps/RestockStep.jsx` | create | quantity + issuance |
| `frontend/src/admin/components/incident/steps/DetailsStep.jsx` | create | narrative + photo + recap |
| `frontend/src/admin/pages/IncidentReportsPage.jsx` | modify | render list + flow |
| `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx` | modify | list first, two buttons |
| `frontend/src/admin/components/IncidentFileModal.jsx` | **delete** | superseded |

---

### Task 1: Pure flow helpers, with tests

Everything here is a pure function. It exists so the two arithmetic conversions that were bugs in production get a test instead of a manual check.

**Files:**
- Create: `frontend/src/admin/utils/incidentFlow.js`
- Test: `frontend/src/admin/utils/incidentFlow.test.js`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/incidentFlow.test.js`:

```js
import { stepsFor, toBackendInstant, totalPiecesFrom, STEP_KEYS } from './incidentFlow';

describe('stepsFor', () => {
  it('gives a stock-affecting reason four steps, outcome step third', () => {
    expect(stepsFor('theft')).toEqual([
      STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.OUTCOME, STEP_KEYS.DETAILS,
    ]);
  });

  it('skips the outcome step for `other`, which moves no stock', () => {
    expect(stepsFor('other')).toEqual([
      STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.DETAILS,
    ]);
  });

  it('treats an unrecognised reason as `other` rather than throwing', () => {
    expect(stepsFor('not_a_real_reason')).toHaveLength(3);
  });
});

describe('toBackendInstant', () => {
  // A bare `datetime-local` value has no zone. Sent verbatim it made the UTC
  // server read a Manila "now" as eight hours ahead, so before_or_equal:now
  // rejected every filing that carried a timestamp.
  it('turns a bare local wall-time into a UTC instant', () => {
    const out = toBackendInstant('2026-08-09T16:02');
    expect(out).toBe(new Date('2026-08-09T16:02').toISOString());
    expect(out).toMatch(/Z$/);
  });

  it('returns null for an empty value rather than an Invalid Date', () => {
    expect(toBackendInstant('')).toBeNull();
    expect(toBackendInstant(null)).toBeNull();
  });
});

describe('totalPiecesFrom', () => {
  // Stock is stored in PIECES everywhere. Reporting "1" for a damaged box used
  // to write off a single piece.
  it('converts packs plus loose pieces to pieces', () => {
    expect(totalPiecesFrom({ packs: 2, loosePieces: 5, quantity: 1, piecesPerPackage: 10 }))
      .toBe(25);
  });

  it('uses the plain quantity when the product has no pack size', () => {
    expect(totalPiecesFrom({ packs: 9, loosePieces: 9, quantity: 3, piecesPerPackage: 1 }))
      .toBe(3);
  });

  it('reads empty and non-numeric inputs as zero, never NaN', () => {
    expect(totalPiecesFrom({ packs: '', loosePieces: '', quantity: '', piecesPerPackage: 10 }))
      .toBe(0);
    expect(totalPiecesFrom({ packs: 'x', loosePieces: '', quantity: '', piecesPerPackage: 0 }))
      .toBe(0);
  });

  it('never returns a negative total', () => {
    expect(totalPiecesFrom({ packs: 0, loosePieces: 0, quantity: -5, piecesPerPackage: 1 }))
      .toBe(0);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend && npm test -- --watchAll=false --testPathPattern=incidentFlow
```

Expected: FAIL — `Cannot find module './incidentFlow'`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/admin/utils/incidentFlow.js`:

```js
import { reasonMeta } from './incidentReasons';

/**
 * The pure parts of the guided incident flow: which steps a reason needs, and
 * the two conversions between what the user types and what the API stores.
 *
 * These live outside the components because both conversions were live bugs —
 * a bare local timestamp the UTC server read as eight hours in the future, and
 * a pack quantity that wrote off a single piece — and a unit test is a cheaper
 * guard than remembering to re-check them by hand.
 */

export const STEP_KEYS = {
  WHAT: 'what',
  WHERE: 'where',
  OUTCOME: 'outcome',
  DETAILS: 'details',
};

/**
 * The step sequence for a reason. Every flow shares three steps; only the
 * outcome-specific one differs, and `none` (the `other` reason) has none at
 * all because nothing about stock is being asserted.
 */
export function stepsFor(reason) {
  const { outcome } = reasonMeta(reason);
  return outcome === 'none'
    ? [STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.DETAILS]
    : [STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.OUTCOME, STEP_KEYS.DETAILS];
}

/**
 * `datetime-local` yields bare local wall-time ("2026-08-09T16:02") with no
 * zone. `new Date()` reads it in the device's zone; toISOString() makes it an
 * unambiguous UTC instant the server can compare from anywhere.
 */
export function toBackendInstant(localValue) {
  return localValue ? new Date(localValue).toISOString() : null;
}

/**
 * The ONE quantity that gets sent. Pack and loose-piece entry is convenience;
 * inventory, transfers and dispatch all store PIECES.
 */
export function totalPiecesFrom({ packs, loosePieces, quantity, piecesPerPackage }) {
  const per = Number(piecesPerPackage) || 0;
  const int = (v) => parseInt(v, 10) || 0;
  const total = per > 1
    ? int(packs) * per + int(loosePieces)
    : int(quantity);
  return Math.max(0, total);
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd frontend && npm test -- --watchAll=false --testPathPattern=incidentFlow
```

Expected: PASS, 10 tests.

- [ ] **Step 5: Lint**

```bash
cd frontend && npx eslint src/admin/utils/incidentFlow.js src/admin/utils/incidentFlow.test.js
```

Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/utils/incidentFlow.js frontend/src/admin/utils/incidentFlow.test.js
git commit -F - <<'EOF'
feat(incidents): pure step-sequencing and quantity/timestamp helpers

Extracts the two conversions that were live bugs — the UTC instant and
the pack-to-pieces total — into tested pure functions before the flow
components are built on them.
EOF
```

---

### Task 2: Extract the incident list into its own component

Pure move, no behaviour change. The Adjust page needs to render the same list, and duplicating a table with nine columns and three filters would guarantee they drift.

**Files:**
- Create: `frontend/src/admin/components/incident/IncidentList.jsx`
- Modify: `frontend/src/admin/pages/IncidentReportsPage.jsx`

- [ ] **Step 1: Create the component**

Move everything from `IncidentReportsPage.jsx` that fetches and renders incidents into `frontend/src/admin/components/incident/IncidentList.jsx`. Copy the code across **verbatim** — including every comment; they document non-obvious decisions (why `occurred_at || created_at`, why "Reported directly", why `packageBreakdown` may be null).

What moves: the `incidents` / `loading` / `error` / `statusFilter` / `reasonFilter` / `locationFilter` / `locations` / `statusNameById` / `selected` state, the two `useEffect`s, `load`, `statusLabel`, the toolbar, the table, and `IncidentResolveDrawer`.

What does NOT move: `filing` state, the Report Incident button, `AdminLayout`, `ak-page`, the `<header>`.

The component owns its own loading and refetching:

```jsx
import React, { useCallback, useEffect, useImperativeHandle, useMemo, useState } from 'react';
import Loader from '../Loader';
import IncidentResolveDrawer from '../IncidentResolveDrawer';
import { incidentReportsAPI, locationsAPI, statusAPI } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { INCIDENT_REASONS, reasonBadge, reasonLabel } from '../../utils/incidentReasons';
import { packageBreakdown } from '../../utils/packageFormat';

/**
 * The incident triage list — filters, table and the resolve drawer.
 *
 * Extracted from IncidentReportsPage so the Report Incident page reached from
 * the barcode scanner can show the same list without a second copy of a
 * nine-column table and three filters that would drift apart.
 *
 * Props:
 *   summaryRef {React.Ref} optional — exposes { reload } so a parent can
 *              refetch after filing, without lifting all the filter state.
 */
const IncidentList = React.forwardRef(function IncidentList(_props, ref) {
  // …state, effects, load, statusLabel — moved verbatim from the page…

  useImperativeHandle(ref, () => ({ reload: load }), [load]);

  return (
    <>
      {/* …toolbar + table + drawer, moved verbatim… */}
    </>
  );
});

export default IncidentList;
```

Note the import depth changes: `../components/X` becomes `../X`, and `../services/api` becomes `../../services/api`. Get these right or the build fails immediately.

- [ ] **Step 2: Rewrite the page to use it**

`IncidentReportsPage.jsx` keeps `AdminLayout`, the header, the Report Incident button, and the `filing` state; it renders `<IncidentList ref={listRef} />` where the toolbar and table were, and calls `listRef.current?.reload()` after a successful file.

The `openCount` / `alertReporterCount` badges in the header read from `incidents`, which now lives inside the list. Move those two badges **into** `IncidentList`, rendered above the toolbar — they belong with the data they summarise, and both pages want them.

- [ ] **Step 3: Verify nothing changed**

```bash
cd frontend && npx eslint src/admin/components/incident/IncidentList.jsx src/admin/pages/IncidentReportsPage.jsx && npm run build
```

Expected: eslint silent, build "Compiled successfully" (a pre-existing warning baseline is acceptable; **new** warnings in these two files are not).

Then load `/admin/incidents` in the browser: the same filters, the same rows, Review still opens the drawer, and resolving still refetches.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/incident/IncidentList.jsx frontend/src/admin/pages/IncidentReportsPage.jsx
git commit -F - <<'EOF'
refactor(incidents): extract the triage list into IncidentList

Pure move, no behaviour change. The Report Incident page reached from the
barcode scanner needs the same list, and a second copy of a nine-column
table with three filters would drift.
EOF
```

---

### Task 3: The step machine and the two shared end steps

At the end of this task the `other` flow works end to end: three steps, no product, filed successfully. The outcome step is stubbed and wired in Tasks 4–6.

**Files:**
- Create: `frontend/src/admin/components/incident/IncidentFlow.jsx`
- Create: `frontend/src/admin/components/incident/steps/WhatHappenedStep.jsx`
- Create: `frontend/src/admin/components/incident/steps/DetailsStep.jsx`

- [ ] **Step 1: WhatHappenedStep**

```jsx
import React from 'react';
import { REASON_GROUPS, reasonMeta } from '../../../utils/incidentReasons';

/**
 * Step 1 — what happened, and when. Controlled: every value and setter comes
 * from IncidentFlow, so stepping back and forward loses nothing.
 */
export default function WhatHappenedStep({
  reason, setReason, occurredAt, setOccurredAt, maxOccurredAt, disabled,
}) {
  const meta = reasonMeta(reason);

  return (
    <>
      <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
        <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>What happened? *</legend>
        {REASON_GROUPS.map((group) => (
          <div key={group.name} style={{ marginBottom: '0.5rem' }}>
            <div className="ak-muted" style={{ fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              {group.name}
            </div>
            {group.reasons.map((r) => (
              <label
                key={r.value}
                style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer', padding: '0.2rem 0' }}
              >
                <input
                  type="radio"
                  name="incident-flow-reason"
                  value={r.value}
                  checked={reason === r.value}
                  onChange={() => setReason(r.value)}
                  disabled={disabled}
                  style={{ marginTop: '0.2rem' }}
                />
                <span>{r.label}</span>
              </label>
            ))}
          </div>
        ))}
        {meta.help && (
          <p className="ak-muted" style={{ fontSize: '0.8rem', margin: '0.2rem 0 0' }}>{meta.help}</p>
        )}
      </fieldset>

      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-occurred">When did it happen? *</label>
        <input
          id="incident-flow-occurred"
          className="ak-input"
          type="datetime-local"
          value={occurredAt}
          max={maxOccurredAt}
          disabled={disabled}
          onChange={(e) => setOccurredAt(e.target.value)}
          style={{ maxWidth: 260 }}
        />
        <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
          Defaults to now. Set it back if you are reporting something you noticed earlier.
        </span>
      </div>
    </>
  );
}
```

- [ ] **Step 2: DetailsStep**

Carry the narrative field and the whole evidence-photo fieldset over from `IncidentFileModal.jsx` lines 622–687 **unchanged** — the `useIncidentPhoto` hook, `CameraCapture`, the provenance badge and the Remove button all keep working as they are. Add a recap above them so the user can check what they entered without stepping back:

```jsx
import React from 'react';
import CameraCapture from '../../CameraCapture';
import { reasonLabel } from '../../../utils/incidentReasons';

/**
 * Final step — the narrative and the optional evidence photo, above a read-only
 * recap of the earlier steps so the filer can check their answers without
 * stepping back through them.
 *
 * `photo` is the whole useIncidentPhoto hook value, owned by IncidentFlow so the
 * chosen file survives a Back/Next round trip.
 */
export default function DetailsStep({
  reason, occurredAt, locationName, productName, totalPieces, showQuantity,
  narrative, setNarrative, photo, cameraOpen, setCameraOpen, disabled,
}) {
  return (
    <>
      <div className="ak-card" style={{ padding: '0.75rem', marginBottom: '0.75rem' }}>
        <div className="ak-muted" style={{ fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
          Reporting
        </div>
        <div style={{ fontSize: '0.9rem', marginTop: '0.25rem' }}>
          <strong>{reasonLabel(reason)}</strong>
          {locationName ? ` at ${locationName}` : ''}
          {productName ? ` · ${productName}` : ''}
          {showQuantity ? ` · ${totalPieces} pcs` : ''}
          {occurredAt ? ` · ${new Date(occurredAt).toLocaleString()}` : ''}
        </div>
      </div>

      {/* …narrative textarea + evidence photo fieldset, from IncidentFileModal 622-687… */}
    </>
  );
}
```

- [ ] **Step 3: IncidentFlow**

The step machine. It owns every piece of state, decides which step renders, and builds the payload in one place.

```jsx
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { incidentReportsAPI, locationsAPI, productsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { reasonMeta } from '../../utils/incidentReasons';
import { stepsFor, toBackendInstant, totalPiecesFrom, STEP_KEYS } from '../../utils/incidentFlow';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
import WhatHappenedStep from './steps/WhatHappenedStep';
import DetailsStep from './steps/DetailsStep';

/** `datetime-local` wants LOCAL YYYY-MM-DDTHH:mm — toISOString() is UTC and would be off by the offset. */
const toLocalInputValue = (date) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`
    + `T${p(date.getHours())}:${p(date.getMinutes())}`;
};

const asArray = (res) => (Array.isArray(res?.data) ? res.data : []);

const STEP_TITLES = {
  [STEP_KEYS.WHAT]: 'What happened',
  [STEP_KEYS.WHERE]: 'Where and what',
  [STEP_KEYS.OUTCOME]: 'Details of the stock',
  [STEP_KEYS.DETAILS]: 'Describe and file',
};

/**
 * The guided incident flow — one wizard whose third step is chosen by what is
 * being reported.
 *
 * Replaces IncidentFileModal, which showed every field for every reason behind
 * conditional rendering. All state lives here and steps are controlled, so Back
 * never loses data and the payload is assembled in exactly one place.
 *
 * Props:
 *   onCancel {() => void}
 *   onFiled  {() => void}  fires ONLY after a successful file; caller refetches.
 */
export default function IncidentFlow({ onCancel, onFiled }) {
  const [stepIndex, setStepIndex] = useState(0);
  const [reason, setReason] = useState('not_found');
  const [occurredAt, setOccurredAt] = useState(() => toLocalInputValue(new Date()));
  const [maxOccurredAt] = useState(() => toLocalInputValue(new Date()));
  const [locationId, setLocationId] = useState('');
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [packs, setPacks] = useState(0);
  const [loosePieces, setLoosePieces] = useState(0);
  const [batchId, setBatchId] = useState('');
  const [actualZone, setActualZone] = useState('');
  const [issuanceId, setIssuanceId] = useState('');
  const [narrative, setNarrative] = useState('');
  const [scanHit, setScanHit] = useState(null);
  const [cameraOpen, setCameraOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(null);

  const [locations, setLocations] = useState([]);
  const [products, setProducts] = useState([]);

  const photo = useIncidentPhoto();
  // Destructured so exhaustive-deps gets a plain identifier — it cannot follow
  // `photo.uploadIfAny` and would demand the whole per-render object.
  const { uploadIfAny: uploadPhotoIfAny } = photo;

  const meta = reasonMeta(reason);
  const outcome = meta.outcome;
  const needsStock = !!meta.affectsStock;

  const steps = useMemo(() => stepsFor(reason), [reason]);
  // Changing the reason can shorten the flow (four steps to three). Clamping
  // rather than resetting keeps the user where they were.
  const safeIndex = Math.min(stepIndex, steps.length - 1);
  const step = steps[safeIndex];

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(asArray(res)))
      .catch(() => setLocations([]));
    productsAPI.getAll({ per_page: 1000 })
      .then((res) => setProducts(asArray(res)))
      .catch(() => setProducts([]));
  }, []);

  // Switching to a reason that does not move stock must not leave stale product
  // / quantity / batch state behind for the payload to pick up.
  useEffect(() => {
    if (!needsStock) {
      setProductId('');
      setQuantity(1);
      setPacks(0);
      setLoosePieces(0);
      setBatchId('');
      setActualZone('');
      setScanHit(null);
    }
  }, [needsStock]);

  // The issuance link only means anything on a restock.
  useEffect(() => {
    if (outcome !== 'restock') setIssuanceId('');
  }, [outcome]);

  const selectedProduct = products.find((p) => String(p.product_id) === String(productId));
  // The products list is capped at 1000, so a SCANNED product may not be in it.
  // The lookup response carries the same two fields, so prefer it when it
  // describes the product currently selected — otherwise a scan of an item
  // outside the cap silently loses its pack size and under-deducts.
  const scannedProduct = scanHit?.product
    && String(scanHit.product.product_id) === String(productId)
    ? scanHit.product
    : null;

  const piecesPerPackage = Number(
    scannedProduct?.pieces_per_package ?? selectedProduct?.pieces_per_package
  ) || 0;
  const packageUnit =
    scannedProduct?.package_unit
    || selectedProduct?.consumable_detail?.package_unit
    || 'pkg';
  const usesPacks = piecesPerPackage > 1;

  const totalPieces = totalPiecesFrom({ packs, loosePieces, quantity, piecesPerPackage });

  const canAdvance = (() => {
    if (step === STEP_KEYS.WHAT) return !!reason && !!occurredAt;
    if (step === STEP_KEYS.WHERE) return !!locationId && (!needsStock || !!productId);
    if (step === STEP_KEYS.OUTCOME) {
      if (outcome === 'writeoff' || outcome === 'restock') return totalPieces >= 1;
      // RetypeResolution refuses to resolve an incident with no batch, so a
      // wrong-type report without one could never be actioned.
      if (outcome === 'retype') return !!batchId;
      if (outcome === 'relocate') return !!batchId && !!actualZone.trim();
      return true;
    }
    if (step === STEP_KEYS.DETAILS) return narrative.trim().length > 0 && !submitting;
    return false;
  })();

  const handleSubmit = useCallback(async () => {
    setSubmitting(true);
    setSubmitError(null);
    try {
      const { photo_path, photo_source } = await uploadPhotoIfAny();
      await incidentReportsAPI.file({
        source_type: 'standalone',
        location_id: Number(locationId),
        product_id: needsStock && productId ? Number(productId) : null,
        quantity: needsStock ? totalPieces : null,
        batch_id: needsStock && batchId ? Number(batchId) : null,
        // expected_zone is where the record SAYS the batch is; actual_zone is
        // where it really is. Only a relocate asserts either.
        expected_zone: outcome === 'relocate' ? (scanHit?.batches
          ?.find((b) => String(b.batch_id) === String(batchId))?.zone_label ?? null) : null,
        actual_zone: outcome === 'relocate' && actualZone.trim() ? actualZone.trim() : null,
        related_issuance_id: outcome === 'restock' && issuanceId ? Number(issuanceId) : null,
        reason,
        narrative: narrative.trim(),
        occurred_at: toBackendInstant(occurredAt),
        photo_path,
        photo_source,
      });
      toast.success('Incident filed. A reviewer will pick it up from the triage list.');
      onFiled?.();
    } catch (err) {
      // Shown in place on this step, with the entered data intact — a failed
      // submit must never throw the user back to step 1.
      setSubmitError(err?.message || 'Failed to file the incident.');
    } finally {
      setSubmitting(false);
    }
  }, [uploadPhotoIfAny, locationId, needsStock, productId, totalPieces, batchId, outcome,
    actualZone, scanHit, issuanceId, reason, narrative, occurredAt, onFiled]);

  const isLast = safeIndex === steps.length - 1;

  return (
    <div className="ak-card" style={{ padding: '1rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.75rem' }}>
        <div>
          <span className="ak-muted" style={{ fontSize: '0.78rem' }}>
            Step {safeIndex + 1} of {steps.length}
          </span>
          <h2 className="ak-label" style={{ margin: '0.15rem 0 0' }}>{STEP_TITLES[step]}</h2>
        </div>
        <button type="button" className="ak-btn ak-btn--sm" onClick={onCancel} disabled={submitting}>
          ← Back to list
        </button>
      </div>

      <div className="ak-field" style={{ gap: '1rem' }}>
        {step === STEP_KEYS.WHAT && (
          <WhatHappenedStep
            reason={reason} setReason={setReason}
            occurredAt={occurredAt} setOccurredAt={setOccurredAt}
            maxOccurredAt={maxOccurredAt} disabled={submitting}
          />
        )}
        {/* STEP_KEYS.WHERE  — Task 4 */}
        {/* STEP_KEYS.OUTCOME — Tasks 5 and 6 */}
        {step === STEP_KEYS.DETAILS && (
          <DetailsStep
            reason={reason}
            occurredAt={occurredAt}
            locationName={locations.find((l) => String(l.location_id ?? l.id) === String(locationId))?.location_name}
            productName={scannedProduct?.product_name || selectedProduct?.product_name}
            totalPieces={totalPieces}
            showQuantity={outcome === 'writeoff' || outcome === 'restock'}
            narrative={narrative} setNarrative={setNarrative}
            photo={photo} cameraOpen={cameraOpen} setCameraOpen={setCameraOpen}
            disabled={submitting}
          />
        )}

        {submitError && <div className="ak-state ak-state--error">{submitError}</div>}

        <div style={{ display: 'flex', justifyContent: 'space-between', gap: '0.6rem' }}>
          <button
            type="button"
            className="ak-btn"
            onClick={() => setStepIndex((i) => Math.max(0, i - 1))}
            disabled={safeIndex === 0 || submitting}
          >
            Back
          </button>
          <button
            type="button"
            className="ak-btn ak-btn--primary"
            onClick={() => (isLast ? handleSubmit() : setStepIndex(safeIndex + 1))}
            disabled={!canAdvance}
          >
            {isLast ? (submitting ? 'Filing…' : 'File incident') : 'Next'}
          </button>
        </div>
      </div>
    </div>
  );
}
```

`piecesPerPackage` is used in this task (it feeds `totalPiecesFrom`), but `usesPacks` and `packageUnit` are not — ESLint's `no-unused-vars` will flag them. Leave both out for now and declare them where they are first needed: `usesPacks` in Task 4's re-expression effect, `packageUnit` in Task 5's quantity control. That way each task lands lint-clean.

- [ ] **Step 4: Verify the `other` flow**

```bash
cd frontend && npx eslint src/admin/components/incident/IncidentFlow.jsx src/admin/components/incident/steps/WhatHappenedStep.jsx src/admin/components/incident/steps/DetailsStep.jsx && npm run build
```

Expected: eslint silent, build compiles. The flow is not yet reachable from any page — that is Tasks 7 and 8.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/IncidentFlow.jsx frontend/src/admin/components/incident/steps/WhatHappenedStep.jsx frontend/src/admin/components/incident/steps/DetailsStep.jsx
git commit -F - <<'EOF'
feat(incidents): step machine plus the two steps every flow shares

IncidentFlow owns all state and builds the payload in one place; steps are
controlled, so Back never loses data.
EOF
```

---

### Task 4: Where and what — location, scan, type, pick

**Files:**
- Create: `frontend/src/admin/components/incident/steps/WhereAndWhatStep.jsx`
- Modify: `frontend/src/admin/components/incident/IncidentFlow.jsx`

- [ ] **Step 1: Create the step**

Carry over the location select (`IncidentFileModal.jsx` 372–387) and the whole scan / type / pick block (426–522) — the text input, the Find button, the 📷 Scan button, `CameraScanner`, the scan-error and scan-hit messages, the filter input and the product select. Keep every comment.

The step needs its own `scanInput` / `scanning` / `scanError` / `camOpen` / `productFilter` state, because none of it is part of the submitted payload; it is the mechanics of choosing a product. `runLookup` moves here too, and calls `onScanHit` and `setProductId` from props.

Header comment:

```jsx
/**
 * Step 2 — where it happened and which product.
 *
 * Three ways in on purpose. Scanning is fastest, but theft and damage are often
 * WHY there is no barcode left to scan, so the picker below is always present
 * and a failed lookup never blocks the report — it shows the error and leaves
 * the picker usable.
 *
 * Product is hidden entirely when the reason moves no stock (`other`).
 */
```

The two behaviours that must survive, both fixes from earlier work:

```jsx
// The lookup is location-scoped, so its verdict expires the moment the location
// does: "Matched serial SN-4471 — In Stock" was a claim about the OLD location,
// and leaving it on screen is misinformation on a compliance record. The
// product is kept — correcting a mis-selected location shouldn't wipe the picker.
useEffect(() => { onScanHit(null); setScanError(null); }, [locationId, onScanHit]);
```

and in `runLookup`, the decode is passed straight in rather than routed through state:

```jsx
/**
 * `rawCode` lets the camera pass its decode straight in. Going through
 * setScanInput first would read a stale value from this closure. A click passes
 * an event object, hence the string guard.
 */
const runLookup = useCallback(async (rawCode) => {
  const code = (typeof rawCode === 'string' ? rawCode : scanInput).trim();
  if (!code) return;
  if (!locationId) { setScanError('Choose a location first.'); return; }
  setScanning(true);
  setScanError(null);
  try {
    const res = await incidentReportsAPI.lookup({ barcode: code, locationId });
    const d = res?.data;
    if (!d?.product?.product_id) throw new Error('No product matches that barcode at this location.');
    // Scanning SETS the product; it does not lock it. A mislabelled barcode is
    // itself a reportable incident (wrong_type), so the picker stays live.
    setProductId(String(d.product.product_id));
    onScanHit({ matched_by: d.matched_by, serial: d.serial, batches: d.batches || [], product: d.product });
  } catch (err) {
    onScanHit(null);
    setScanError(err?.message || 'No product matches that barcode at this location.');
  } finally {
    setScanning(false);
  }
}, [scanInput, locationId, setProductId, onScanHit]);
```

`onScanHit` must be wrapped in `useCallback` in `IncidentFlow` (`const handleScanHit = useCallback((hit) => setScanHit(hit), [])`) — an inline arrow would be a new identity every render and re-fire the location effect in a loop.

- [ ] **Step 2: Wire it into IncidentFlow**

Import it and render it under `step === STEP_KEYS.WHERE`, passing `locations`, `products`, `locationId`/`setLocationId`, `productId`/`setProductId`, `needsStock`, `handleScanHit` and `disabled={submitting}`.

Add the re-expression effect, carried from `IncidentFileModal.jsx` 244–253 with its comment intact:

```jsx
// Changing product can change the pack size, or remove it. Re-express the
// current total in the new product's terms rather than silently keeping numbers
// that now mean something different.
//
// totalPieces is deliberately NOT a dependency: it is derived from packs and
// loosePieces, so including it would re-run this on every keystroke and fight
// the user's typing.
useEffect(() => {
  if (usesPacks) {
    const { boxes, loose } = piecesToPackages(totalPieces, piecesPerPackage);
    setPacks(boxes);
    setLoosePieces(loose);
  } else {
    setQuantity(totalPieces > 0 ? totalPieces : 1);
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [productId, piecesPerPackage, usesPacks]);
```

This needs `import { piecesToPackages } from '../../utils/packageFormat';` and the `usesPacks` / `piecesPerPackage` consts from Task 3.

Also clear the batch when either product or location changes — a batch belongs to one product at one location:

```jsx
useEffect(() => { setBatchId(''); }, [productId, locationId]);
```

- [ ] **Step 3: Verify**

```bash
cd frontend && npx eslint src/admin/components/incident/steps/WhereAndWhatStep.jsx src/admin/components/incident/IncidentFlow.jsx && npm run build
```

Expected: eslint silent, build compiles.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/incident/steps/WhereAndWhatStep.jsx frontend/src/admin/components/incident/IncidentFlow.jsx
git commit -F - <<'EOF'
feat(incidents): where-and-what step with scan, type and pick

Carries over the location-change scan-clear and the pack re-expression on
product change — both fixes the deleted modal earned the hard way.
EOF
```

---

### Task 5: The two quantity-bearing outcome steps

**Files:**
- Create: `frontend/src/admin/components/incident/steps/WriteOffStep.jsx`
- Create: `frontend/src/admin/components/incident/steps/RestockStep.jsx`
- Modify: `frontend/src/admin/components/incident/IncidentFlow.jsx`

Both need the batch list. Load it in `IncidentFlow` (not in each step, or two mounts would fetch it twice), carrying the lazy-fetch comment from `IncidentFileModal.jsx` 131–144:

```jsx
// Batches are NOT loaded on mount. GET /inventory-batches returns every batch
// with stock remaining in the caller's location scope in one un-paginated array
// (a centralized user sees the whole org), which is the largest list here and
// is only needed once a product has been chosen. Fetch it lazily on that first
// need, then filter client-side — the endpoint ignores query params, so a
// per-product request would fetch the same payload again.
useEffect(() => {
  if (!needsStock || !productId || batches !== null || batchesLoading) return;
  setBatchesLoading(true);
  inventoryBatchesAPI.getAll()
    .then((res) => setBatches(asArray(res)))
    .catch(() => setBatches([]))
    .finally(() => setBatchesLoading(false));
}, [needsStock, productId, batches, batchesLoading]);

const availableBatches = useMemo(() => {
  if (!Array.isArray(batches) || !productId) return [];
  return batches.filter((b) => {
    if (String(b.product_id) !== String(productId)) return false;
    if (locationId && String(b.location_id) !== String(locationId)) return false;
    return true;
  });
}, [batches, productId, locationId]);
```

- [ ] **Step 1: WriteOffStep**

Quantity (the pack + loose pair from `IncidentFileModal.jsx` 525–576) and the optional batch select (578–618), keeping the FIFO-hold warning verbatim:

> Naming batch #N holds it out of FIFO picking until this report is reviewed — nobody can pick from it in the meantime. Leave it blank if the incident is not specific to one batch.

Header comment:

```jsx
/**
 * Outcome step for the four write-off reasons (not found, theft, damaged,
 * damaged in delivery). How much is gone, and — optionally — from which batch.
 *
 * Quantity is entered in packs plus loose pieces when the product has a pack
 * size, because reporting "1" for a damaged box used to write off a single
 * PIECE. The total in pieces is what gets sent.
 */
```

- [ ] **Step 2: RestockStep**

The same quantity control, plus the issuance search from `IncidentFileModal.jsx` 389–421 (the debounced `issuancesAPI.getAll({ search, per_page: 20 })` and the result select), and the batch select with the **return** variant of the warning:

> Resolving this report puts the returned stock back ONTO batch #N. Name the batch the item originally shipped from, or leave it blank if you are not sure.

Keep the behaviour where picking an issuance sets the location from it. The debounce state (`issuanceSearch`, `issuanceResults`) lives in the step; only `issuanceId` is lifted to `IncidentFlow`, since only it is submitted.

Header comment:

```jsx
/**
 * Outcome step for a customer return. How much came back, which batch it goes
 * onto, and — optionally — the issuance it originally went out on.
 *
 * The issuance is optional because a return can arrive with no paperwork; when
 * it is named, the location follows from it, which is almost always right.
 */
```

- [ ] **Step 3: Wire both into IncidentFlow**

```jsx
{step === STEP_KEYS.OUTCOME && outcome === 'writeoff' && (
  <WriteOffStep
    packs={packs} setPacks={setPacks}
    loosePieces={loosePieces} setLoosePieces={setLoosePieces}
    quantity={quantity} setQuantity={setQuantity}
    usesPacks={usesPacks} piecesPerPackage={piecesPerPackage}
    packageUnit={packageUnit} totalPieces={totalPieces}
    batchId={batchId} setBatchId={setBatchId}
    availableBatches={availableBatches} batchesLoading={batchesLoading}
    productId={productId} disabled={submitting}
  />
)}
{step === STEP_KEYS.OUTCOME && outcome === 'restock' && (
  <RestockStep
    /* …same quantity and batch props… */
    issuanceId={issuanceId} setIssuanceId={setIssuanceId}
    setLocationId={setLocationId}
  />
)}
```

- [ ] **Step 4: Verify**

```bash
cd frontend && npx eslint src/admin/components/incident/steps/WriteOffStep.jsx src/admin/components/incident/steps/RestockStep.jsx src/admin/components/incident/IncidentFlow.jsx && npm run build
```

Expected: eslint silent, build compiles.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/steps/WriteOffStep.jsx frontend/src/admin/components/incident/steps/RestockStep.jsx frontend/src/admin/components/incident/IncidentFlow.jsx
git commit -F - <<'EOF'
feat(incidents): write-off and restock outcome steps

Pack-plus-pieces entry on both, and each states what resolving will do to
the named batch — held out of FIFO, or restocked onto.
EOF
```

---

### Task 6: The two batch-only outcome steps

**Files:**
- Create: `frontend/src/admin/components/incident/steps/RetypeStep.jsx`
- Create: `frontend/src/admin/components/incident/steps/RelocateStep.jsx`
- Modify: `frontend/src/admin/components/incident/IncidentFlow.jsx`

- [ ] **Step 1: RetypeStep**

Batch is **required** here, unlike in the write-off step. Reuse the same batch select markup, with the empty option reading `Select the batch that is mislabelled…` and no "Not batch-specific" escape.

```jsx
/**
 * Outcome step for a wrong-type report — the batch was received as the wrong
 * product.
 *
 * The batch is REQUIRED: RetypeResolution refuses to resolve without one,
 * because with no batch there is nothing to re-type and closing the report
 * would leave the real stock wrong while the record reads as fixed.
 *
 * What the item ACTUALLY is stays the reviewer's call in the resolve drawer —
 * incident_reports has no column to hold a filer's suggestion. Hence the prompt
 * to name it in the description on the next step.
 */
```

Below the select, tell the filer what to do with that information:

```jsx
<div className="ak-state" style={{ marginTop: '0.4rem', fontSize: '0.82rem', textAlign: 'left' }}>
  On the next step, describe what the item on the shelf actually is — brand,
  model, anything on the label. A reviewer picks the corrected product from
  that description when they resolve this report.
</div>
```

- [ ] **Step 2: RelocateStep**

Batch (required) plus a free-text actual-zone input. Show the batch's recorded zone beside it, since that is what is being corrected:

```jsx
/**
 * Outcome step for a zone mismatch — nothing is missing, the batch record just
 * points at the wrong zone.
 *
 * No quantity: nothing moves. RelocateResolution rewrites the batch's
 * zone_label from actual_zone, which store() has always accepted and the old
 * modal never sent — so standalone zone-mismatch reports resolved to a silent
 * no-op. This step is what makes them do something.
 */
```

```jsx
<div className="ak-field">
  <label className="ak-label" htmlFor="incident-flow-actual-zone">Where is it actually? *</label>
  <input
    id="incident-flow-actual-zone"
    className="ak-input"
    type="text"
    maxLength={100}
    value={actualZone}
    onChange={(e) => setActualZone(e.target.value)}
    placeholder="e.g. A-12"
    disabled={disabled}
    style={{ maxWidth: 260 }}
  />
  {selectedBatch && (
    <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
      Recorded as <strong>{selectedBatch.zone_label || 'no zone'}</strong>.
      Resolving this report rewrites it to what you enter here.
    </span>
  )}
</div>
```

- [ ] **Step 3: Wire both into IncidentFlow**

Two more branches under `step === STEP_KEYS.OUTCOME`, on `outcome === 'retype'` and `outcome === 'relocate'`. `RelocateStep` takes `actualZone` / `setActualZone`; both take the batch props.

`expected_zone` in the payload reads from `scanHit?.batches`, which is only populated when the user scanned. Change it to read from `availableBatches` instead, which is always loaded once a product is chosen:

```jsx
expected_zone: outcome === 'relocate'
  ? (availableBatches.find((b) => String(b.batch_id) === String(batchId))?.zone_label ?? null)
  : null,
```

Update the `handleSubmit` dep array accordingly (`availableBatches` replaces `scanHit`).

- [ ] **Step 4: Verify**

```bash
cd frontend && npx eslint src/admin/components/incident/steps/RetypeStep.jsx src/admin/components/incident/steps/RelocateStep.jsx src/admin/components/incident/IncidentFlow.jsx && npm run build
```

Expected: eslint silent, build compiles.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/steps/RetypeStep.jsx frontend/src/admin/components/incident/steps/RelocateStep.jsx frontend/src/admin/components/incident/IncidentFlow.jsx
git commit -F - <<'EOF'
feat(incidents): retype and relocate outcome steps

Both require a batch. Relocate now captures actual_zone, which store() has
always accepted and the old modal never sent — so a standalone zone-mismatch
report resolved to a silent no-op.
EOF
```

---

### Task 7: Wire the sidebar page and delete the modal

**Files:**
- Modify: `frontend/src/admin/pages/IncidentReportsPage.jsx`
- Delete: `frontend/src/admin/components/IncidentFileModal.jsx`

- [ ] **Step 1: Swap the modal for the flow**

Replace the `IncidentFileModal` import with `IncidentFlow`, and render the flow **in place of** the list when filing:

```jsx
{filing ? (
  <IncidentFlow
    onCancel={() => setFiling(false)}
    onFiled={() => { setFiling(false); listRef.current?.reload(); }}
  />
) : (
  <IncidentList ref={listRef} />
)}
```

Hide the Report Incident button while the flow is open — the flow has its own "← Back to list".

- [ ] **Step 2: Confirm nothing else imports the modal**

```bash
grep -rn IncidentFileModal frontend/src
```

Expected: only `BarcodeAdjustmentPage.jsx` (handled in Task 8). If anything else appears, stop and report it.

- [ ] **Step 3: Delete it after Task 8's page is updated**

`BarcodeAdjustmentPage.jsx` still imports it, so deleting now breaks the build. Do the deletion in Task 8's commit instead, and note here that it is pending.

- [ ] **Step 4: Verify**

```bash
cd frontend && npx eslint src/admin/pages/IncidentReportsPage.jsx && npm run build
```

Then in the browser at `/admin/incidents`: the list is the first thing shown; Report Incident replaces it with step 1 of 4; picking **Other** drops it to 3 steps; "← Back to list" returns without filing.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/pages/IncidentReportsPage.jsx
git commit -F - <<'EOF'
feat(incidents): sidebar page files through the guided flow

The list stays the page; the flow replaces it inline rather than opening a
modal over it.
EOF
```

---

### Task 8: List-first Report Incident page, and remove the modal

**Files:**
- Modify: `frontend/src/admin/pages/BarcodeAdjustmentPage.jsx`
- Delete: `frontend/src/admin/components/IncidentFileModal.jsx`

The user's complaint: "I don't like that we can see first the adjust when the user goes to Report Incident — I want to see first the list of incident reports."

- [ ] **Step 1: Restructure the page**

Directly under the `<header>` (which stays as it is), put the two action buttons and then the list. Both are permission-gated: `canFileIncident` already exists at line 422, and the Adjust section is for users who can submit corrections.

```jsx
{/* Mode 1 — the incident flow, replacing everything below the header. */}
{incidentOpen ? (
  <IncidentFlow
    onCancel={() => setIncidentOpen(false)}
    onFiled={() => { setIncidentOpen(false); listRef.current?.reload(); }}
  />
) : adjustOpen ? (
  <>{/* …the three existing STEP cards, unchanged… */}</>
) : (
  <>
    <div className="ak-actions" style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap', marginBottom: '1rem' }}>
      {canFileIncident && (
        <button type="button" className="ak-btn ak-btn--primary" onClick={() => setIncidentOpen(true)}>
          Report an incident
        </button>
      )}
      {canSubmitCorrection && (
        <button type="button" className="ak-btn" onClick={() => setAdjustOpen(true)}>
          Correct a receiving scan
        </button>
      )}
    </div>
    <IncidentList ref={listRef} />
  </>
)}
```

Add `const [adjustOpen, setAdjustOpen] = useState(false)` and `const listRef = useRef(null)`. Delete the two intro cards at lines 879–897 — the buttons replace them. Keep the existing "Back" header button.

When the Adjust flow is open, give it its own way back:

```jsx
<button type="button" className="ak-btn ak-btn--sm" onClick={() => setAdjustOpen(false)}>
  ← Back to list
</button>
```

An inventory analyst has no `inventory_adjustments.create`, so they see only the Report button — which is the whole reason the incident sidebar entry was kept for them.

- [ ] **Step 2: Delete the modal**

```bash
git rm frontend/src/admin/components/IncidentFileModal.jsx
```

Remove its import from `BarcodeAdjustmentPage.jsx` (line 21) and the `incidentOpen` modal render at the bottom of the file.

- [ ] **Step 3: Verify**

```bash
cd frontend && npx eslint src/admin/pages/BarcodeAdjustmentPage.jsx && npm run build
```

Expected: eslint silent (the page has a large pre-existing warning baseline — no **new** warnings), build compiles. Confirm no `IncidentFileModal` reference survives:

```bash
grep -rn IncidentFileModal frontend/src || echo "clean"
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/BarcodeAdjustmentPage.jsx frontend/src/admin/components/IncidentFileModal.jsx
git commit -F - <<'EOF'
feat(incidents): list-first Report Incident page, drop IncidentFileModal

The page opened on the receiving-correction form and never showed the
incident list at all. Now the list is the page, with Report and Correct as
actions above it, each gated on its own permission.

The 703-line modal is gone; its parts live in the flow's steps.
EOF
```

---

### Task 9: Verification

No code changes unless something below fails.

- [ ] **Step 1: Frontend tests**

```bash
cd frontend && npm test -- --watchAll=false
```

Expected: all pass, including `incidentFlow.test.js` and the pre-existing `alertTarget.test.js`.

- [ ] **Step 2: Lint every new and modified file**

```bash
cd frontend && npx eslint \
  src/admin/utils/incidentFlow.js \
  src/admin/utils/incidentFlow.test.js \
  src/admin/components/incident/IncidentList.jsx \
  src/admin/components/incident/IncidentFlow.jsx \
  src/admin/components/incident/steps/*.jsx \
  src/admin/pages/IncidentReportsPage.jsx \
  src/admin/pages/BarcodeAdjustmentPage.jsx
```

Expected: no errors. A file that is never imported is never linted by the build — this explicit list is the point.

- [ ] **Step 3: Production build**

```bash
cd frontend && npm run build
```

Expected: "Compiled successfully."

- [ ] **Step 4: Backend regression check**

The backend was not touched; this confirms it.

```bash
cd back-end && php artisan test --filter=Incident
```

Expected: all incident tests pass. If any fail, this change touched something it should not have — report it rather than editing a test.

Then the full suite:

```bash
cd back-end && php artisan test
```

Expected: the known baseline only — 9 `WarrantyClaimTest` failures, plus possibly the order-dependent `SerialRowMovementColumnsTest`. **Any other failure is a regression.**

- [ ] **Step 5: Manual pass — all five flows**

`npm run demo`, then sign in and walk each one. Nothing automated covers any of this.

1. **Write-off with packs** — a consumable with a pack size, enter 1 pack, confirm the running total reads the pack size in pieces (not 1), file it, and confirm the list row shows the piece count with the pack breakdown beside it.
2. **Re-type** — Next stays disabled until a batch is chosen.
3. **Relocate** — no quantity field; file it, open it in the resolve drawer, and confirm the Zone column reads `<old> → <new>` rather than `— → —`.
4. **Restock** — search an issuance by DR number, confirm picking one sets the location, and confirm it can be skipped.
5. **Other** — three steps, no product step content, files successfully.

Then across all five: Back preserves what was entered; a bad barcode on step 2 shows an error and still lets the picker be used; a photo taken with the camera still uploads and appears for the reviewer.

- [ ] **Step 6: Report**

State plainly what passed, what failed, and anything left undone. Do not report completion if any step above did not run.

---

## Notes for whoever picks this up

- The branch is several commits ahead of `origin/backupV3` and has **not** been pushed. Do not push without asking.
- `airkingv5.sql` carries ~1900 lines of unrelated dev-data drift. Leave it alone.
- If a step's markup drifts from what this plan shows, prefer what `IncidentFileModal.jsx` did — it is the version that was tested against a real device. Read it from git history after Task 8 deletes it: `git show HEAD~1:frontend/src/admin/components/IncidentFileModal.jsx`.
