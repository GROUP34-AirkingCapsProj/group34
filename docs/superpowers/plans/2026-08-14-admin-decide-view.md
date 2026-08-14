# Admin Decision View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the admin's incident decision screen show the inspector's evidence, findings, per-unit outcomes and signatures, which currently render as four empty sections.

**Architecture:** Add read-only sibling components mirroring `InspectionFields.jsx`, and switch on mode in `IncidentDocument` so `decide` renders them where `inspect` renders the editable fields. The sections themselves stay dumb. No backend change — `GET /incident-reports/{id}` already returns everything needed, and the evidence photo route is already `read`-gated.

**Tech Stack:** React 19, Create React App (`react-scripts` 5), Jest + React Testing Library.

**Spec:** `docs/superpowers/specs/2026-08-14-admin-decide-view-design.md`

---

## Background the engineer needs

The incident report is **one document in three modes**. `IncidentDocument.jsx`
renders the same sections for `file` (reporter writes), `inspect` (inspector
writes below the rule) and `decide` (admin reads everything, writes nothing).

The four sections below the rule — Attachments, Findings, Outcome per unit,
Signatures — receive their content as `children`. Today those children are gated
on `inspecting`, so in `decide` mode each child is the boolean `false`. The
sections render `{children ?? fallback}`, and **`false ?? fallback` is `false`**
because `??` only falls back on `null`/`undefined`. React renders `false` as
nothing. Result: four headings with empty bodies, and an admin pressing Confirm
— the one action that moves stock — without seeing what they are confirming.

Two rules govern all new code here:

1. **Never render an absence as a blank.** "No outcome was recorded on this
   unit" is information; empty space is not. This is the entire lesson of the
   bug being fixed.
2. **Assert on content, never on CSS classes.** The existing test
   `IncidentDocument.test.jsx:70` passes against the blank sections because it
   only checks for the `incdoc__section--readonly` class. That is how this
   shipped.

### Running the tests

All commands run from the `frontend` directory.

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

CRA's Jest config **resets mocks between tests**, which wipes any implementation
given in a `jest.mock` factory. Arm mock return values in `beforeEach`, following
`IncidentDocument.test.jsx:33-37`.

---

## File Structure

**Created:**

| File | Responsibility |
|------|----------------|
| `frontend/src/admin/components/incident/EvidencePhoto.jsx` | `EvidenceThumbnail` — fetches one private-disk evidence photo as a blob and renders it, optionally click-to-enlarge. Moved here because both halves of the document now use it. |
| `frontend/src/admin/components/incident/PhotoLightbox.jsx` | A full-screen overlay for one already-loaded image URL. |
| `frontend/src/admin/components/incident/InspectionSummary.jsx` | `EvidenceSummary`, `FindingsSummary`, `OutcomeSummary`, `SignatureSummary` — the read-only mirror of `InspectionFields.jsx`. Pure presentation; imports no mutating API call and no `toast`. |
| `frontend/src/admin/components/incident/InspectionSummary.test.jsx` | Unit tests for the four summaries. |

**Modified:**

| File | Change |
|------|--------|
| `frontend/src/admin/components/incident/EvidenceChecklist.jsx` | Delete the inlined `EvidenceThumbnail`; import it from `EvidencePhoto`. Re-export it so any future importer still resolves. |
| `frontend/src/admin/components/incident/IncidentDocument.jsx` | Four children expressions become mode ternaries. |
| `frontend/src/admin/components/incident/sections/AttachmentsSection.jsx` | `children ?? fallback` → `children \|\| fallback` |
| `frontend/src/admin/components/incident/sections/FindingsSection.jsx` | same |
| `frontend/src/admin/components/incident/sections/OutcomesSection.jsx` | same |
| `frontend/src/admin/components/incident/sections/SignaturesSection.jsx` | same |
| `frontend/src/admin/components/incident/ReportPhoto.jsx` | Click-to-enlarge, so the reporter's photo behaves like the evidence photos. |
| `frontend/src/admin/components/incident/IncidentDocument.test.jsx` | Add decide-mode content tests. |

**Note on an import that looks wrong but is not:** `InspectionSummary.jsx`
imports `requirementMeta` from `EvidenceChecklist.jsx`. That is a pure label
lookup, and `sections/AttachmentsSection.jsx:4` already imports it the same way.
Follow the existing precedent; do not move it.

---

### Task 1: Move `EvidenceThumbnail` into its own file

A pure refactor with no behaviour change. `EvidenceThumbnail` is currently only
used inside `EvidenceChecklist.jsx` itself, so nothing outside needs updating —
but the read-only summary is about to use it, and importing it from the upload
component would couple the admin's read path to the inspector's write UI.

**Files:**
- Create: `frontend/src/admin/components/incident/EvidencePhoto.jsx`
- Modify: `frontend/src/admin/components/incident/EvidenceChecklist.jsx` (delete lines 374-420, adjust imports)

- [ ] **Step 1: Create the new file**

Create `frontend/src/admin/components/incident/EvidencePhoto.jsx`:

```jsx
import React, { useEffect, useState } from 'react';
import { incidentReportsAPI } from '../../services/api';

/**
 * One evidence photo, fetched from the private disk.
 *
 * Evidence photos sit on the private `local` disk behind an authenticated
 * route, so an <img src> would 401 — fetch a blob and hand <img> an object URL.
 *
 * `revoked` guards both settle paths so a slow failing fetch cannot blank a
 * thumbnail that has since loaded, and the object URL is released on unmount.
 *
 * It lives in its own file because BOTH halves of the document use it now: the
 * inspector's editable checklist and the admin's read-only summary. Leaving it
 * inside EvidenceChecklist would make the read-only view import from the upload
 * UI.
 */
export function EvidenceThumbnail({ incidentId, evidenceId, size = 64 }) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let revoked = false;
    let objectUrl = null;
    setUrl(null);
    setFailed(false);

    incidentReportsAPI.evidencePhotoBlob(incidentId, evidenceId)
      .then((blob) => {
        if (revoked) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => { if (!revoked) setFailed(true); });

    return () => {
      revoked = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [incidentId, evidenceId]);

  if (failed) {
    return <span className="ak-muted" style={{ fontSize: '0.8rem' }}>Photo attached — it could not be loaded.</span>;
  }

  if (!url) {
    return <span className="ak-muted" style={{ fontSize: '0.8rem' }}>Loading photo…</span>;
  }

  return (
    <img
      src={url}
      alt="Incident evidence"
      style={{ width: size, height: size, objectFit: 'cover', borderRadius: 6, border: '1px solid var(--ak-line)' }}
    />
  );
}

export default EvidenceThumbnail;
```

- [ ] **Step 2: Delete the old copy from `EvidenceChecklist.jsx`**

Delete lines 374-420 of `frontend/src/admin/components/incident/EvidenceChecklist.jsx` — the entire `/** Evidence photos sit on a private disk… */` docblock and the `export function EvidenceThumbnail(…) { … }` that follows it, to the end of the file.

- [ ] **Step 3: Import it back in `EvidenceChecklist.jsx`**

Change line 1-6 of `frontend/src/admin/components/incident/EvidenceChecklist.jsx` from:

```jsx
import React, { useCallback, useEffect, useState } from 'react';
import CameraCapture from '../CameraCapture';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
import { incidentReportsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { requirementsFor } from '../../utils/incidentDocument';
```

to:

```jsx
import React, { useCallback, useState } from 'react';
import CameraCapture from '../CameraCapture';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
import { incidentReportsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { requirementsFor } from '../../utils/incidentDocument';
import { EvidenceThumbnail } from './EvidencePhoto';

// Re-exported from its old home so an importer that has not been updated still
// resolves. It moved because the admin's read-only summary needs it too.
export { EvidenceThumbnail };
```

`useEffect` is dropped from the React import because the only thing using it was the component just deleted. Leaving it would fail the `no-unused-vars` lint CRA runs as a build warning.

- [ ] **Step 4: Run the existing suite to prove nothing changed**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "IncidentDocument"
```

Expected: PASS, all existing tests green. This is a refactor — a failure here means the move broke something.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/EvidencePhoto.jsx frontend/src/admin/components/incident/EvidenceChecklist.jsx
git commit -m "refactor(incidents): give the evidence thumbnail its own file

Both halves of the document need it now — the inspector's checklist and
the admin's read-only summary — and importing it from the upload UI would
couple the admin's read path to the inspector's write path."
```

---

### Task 2: The photo lightbox

64px is too small to judge a defect. The admin decides on these photos, so they
must be able to open one full-size.

**Files:**
- Create: `frontend/src/admin/components/incident/PhotoLightbox.jsx`
- Modify: `frontend/src/admin/components/incident/EvidencePhoto.jsx`
- Test: `frontend/src/admin/components/incident/InspectionSummary.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/incident/InspectionSummary.test.jsx`:

```jsx
import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { EvidenceThumbnail } from './EvidencePhoto';
import { incidentReportsAPI } from '../../services/api';

/**
 * The admin's read-only half of the incident document.
 *
 * These assert on CONTENT, never on CSS classes. The decide-mode test in
 * IncidentDocument.test.jsx passed for months against four completely empty
 * sections because it only checked that each section carried the readonly
 * class — which is exactly how an admin came to be asked to confirm a stock
 * movement they could not see.
 */

jest.mock('../../services/api', () => ({
  incidentReportsAPI: {
    evidencePhotoBlob: jest.fn(),
    photoBlob: jest.fn(),
  },
}));

// jsdom has no object-URL plumbing.
beforeEach(() => {
  global.URL.createObjectURL = jest.fn(() => 'blob:fake-url');
  global.URL.revokeObjectURL = jest.fn();
  incidentReportsAPI.evidencePhotoBlob.mockResolvedValue(new Blob(['x']));
  incidentReportsAPI.photoBlob.mockResolvedValue(new Blob(['x']));
});

describe('EvidenceThumbnail', () => {
  test('opens the photo full-size without fetching it a second time', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    const thumb = await screen.findByRole('button', { name: /enlarge/i });
    expect(incidentReportsAPI.evidencePhotoBlob).toHaveBeenCalledTimes(1);

    fireEvent.click(thumb);

    // The overlay shows the SAME object URL the thumbnail already holds.
    const dialog = await screen.findByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(incidentReportsAPI.evidencePhotoBlob).toHaveBeenCalledTimes(1);
  });

  test('closes on Escape', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  test('a photo that failed to load is not clickable', async () => {
    incidentReportsAPI.evidencePhotoBlob.mockRejectedValue(new Error('403'));

    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /enlarge/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: FAIL — `Unable to find an accessible element with the role "button"`. The thumbnail currently renders a bare `<img>` with no `enlargeable` support.

- [ ] **Step 3: Create the lightbox**

Create `frontend/src/admin/components/incident/PhotoLightbox.jsx`:

```jsx
import React, { useEffect } from 'react';

/**
 * One photo, full-size, over a dimmed page.
 *
 * Takes a URL that is ALREADY LOADED rather than fetching its own. The
 * thumbnail that opens it holds an object URL for a blob it has already pulled
 * from the private disk, and re-fetching would double the traffic and leak a
 * second object URL that nothing revokes.
 *
 * Closes on the backdrop and on Escape. The image itself stops propagation, so
 * clicking the thing you came to look at does not dismiss it.
 */
export default function PhotoLightbox({ url, alt, onClose }) {
  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={alt || 'Photo'}
      onClick={onClose}
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.82)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '2rem',
        zIndex: 2000,
      }}
    >
      <img
        src={url}
        alt={alt || 'Photo'}
        onClick={(e) => e.stopPropagation()}
        style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain', borderRadius: 8 }}
      />
      <button
        type="button"
        className="ak-btn"
        onClick={onClose}
        style={{ position: 'absolute', top: '1rem', right: '1rem' }}
      >
        Close
      </button>
    </div>
  );
}
```

- [ ] **Step 4: Make the thumbnail enlargeable**

In `frontend/src/admin/components/incident/EvidencePhoto.jsx`, add the import at the top:

```jsx
import PhotoLightbox from './PhotoLightbox';
```

Change the signature and the render. Replace:

```jsx
export function EvidenceThumbnail({ incidentId, evidenceId, size = 64 }) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);
```

with:

```jsx
export function EvidenceThumbnail({
  incidentId, evidenceId, size = 64, enlargeable = false, alt = 'Incident evidence',
}) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);
```

and replace the final `return (<img … />)` block with:

```jsx
  const img = (
    <img
      src={url}
      alt={alt}
      style={{ width: size, height: size, objectFit: 'cover', borderRadius: 6, border: '1px solid var(--ak-line)' }}
    />
  );

  if (!enlargeable) return img;

  return (
    <>
      <button
        type="button"
        // 64px cannot show whether a compressor is seized. The admin decides on
        // these photographs, so opening one full-size is not a nicety.
        title={`${alt} — click to enlarge`}
        onClick={() => setOpen(true)}
        style={{ background: 'none', border: 'none', padding: 0, cursor: 'zoom-in', lineHeight: 0 }}
      >
        {img}
      </button>
      {open && <PhotoLightbox url={url} alt={alt} onClose={() => setOpen(false)} />}
    </>
  );
```

The `title` is what gives the button its accessible name, which is what the test queries by `/enlarge/i`.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/incident/PhotoLightbox.jsx frontend/src/admin/components/incident/EvidencePhoto.jsx frontend/src/admin/components/incident/InspectionSummary.test.jsx
git commit -m "feat(incidents): let an evidence photo be opened full-size

64px cannot show whether a compressor is seized, and the admin decides on
these photographs. The overlay reuses the object URL the thumbnail already
holds rather than fetching the blob a second time."
```

---

### Task 3: `FindingsSummary`

**Files:**
- Create: `frontend/src/admin/components/incident/InspectionSummary.jsx`
- Modify: `frontend/src/admin/components/incident/InspectionSummary.test.jsx`

- [ ] **Step 1: Write the failing test**

Add to the import block at the **top** of `frontend/src/admin/components/incident/InspectionSummary.test.jsx` (CRA lints `import/first`, so imports must not be appended at the bottom):

```jsx
import { FindingsSummary } from './InspectionSummary';
```

Then append the tests to the same file:

```jsx
describe('FindingsSummary', () => {
  test('shows the findings, the ASC fact, and who inspected it', () => {
    render(<FindingsSummary report={{
      inspection_findings: 'Compressor seized.\nCasing intact.',
      checked_by_asc: true,
      inspected_by_name: 'Rita Cruz',
      inspected_at: '2026-08-13T09:15:00+08:00',
    }} />);

    // Line breaks are preserved rather than collapsed — the inspector wrote two
    // separate observations, not one sentence.
    expect(screen.getByText(/Compressor seized\./)).toBeInTheDocument();
    expect(screen.getByText(/Checked by an authorised service centre/i)).toBeInTheDocument();
    expect(screen.getByText(/Inspected by Rita Cruz/)).toBeInTheDocument();
  });

  test('states the ASC fact when it is false, rather than staying silent', () => {
    render(<FindingsSummary report={{ inspection_findings: 'x', checked_by_asc: false }} />);

    expect(screen.getByText(/Not checked by an authorised service centre/i)).toBeInTheDocument();
  });

  // The rule this whole change exists to enforce: an absence is stated, never
  // rendered as blank space.
  test('says so when no findings were written', () => {
    render(<FindingsSummary report={{ inspection_findings: null, checked_by_asc: false }} />);

    expect(screen.getByText(/recorded no written findings/i)).toBeInTheDocument();
  });

  test('says so when nobody has inspected it yet', () => {
    render(<FindingsSummary report={{ inspection_findings: null }} />);

    expect(screen.getByText(/No inspection has been submitted/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: FAIL — `Cannot find module './InspectionSummary'`.

- [ ] **Step 3: Create the file with `FindingsSummary`**

Create `frontend/src/admin/components/incident/InspectionSummary.jsx`:

```jsx
import React from 'react';

/**
 * The inspector's half of the document, READ-ONLY — the admin's view.
 *
 * The mirror of InspectionFields.jsx, which holds the editable versions. They
 * are separate files rather than one mode-aware set so neither grows a branch
 * inside it, and so the admin's rendering has no write path to reach by
 * accident: nothing here imports a mutating API call or `toast`.
 *
 * That matters beyond tidiness. setOutcome is gated on `incident_reports.inspect`
 * while decide is gated on `resolve`, so an admin cannot change an outcome even
 * if a control were rendered — a dropdown here would 403 rather than save.
 *
 * ONE RULE GOVERNS EVERY COMPONENT BELOW: an absence is stated in words, never
 * rendered as blank space. "No outcome was recorded on this unit" is
 * information. An empty section is what caused this file to be written — the
 * admin's whole lower half rendered blank and they were asked to confirm a
 * stock movement they could not see.
 */

/** When the inspection happened, in the reader's own locale. */
const formatInstant = (iso) => {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d.toLocaleString();
};

/** What the inspection concluded, who concluded it, and when. */
export function FindingsSummary({ report }) {
  const findings = String(report?.inspection_findings || '').trim();
  const who = report?.inspected_by_name;
  const when = formatInstant(report?.inspected_at);

  return (
    <>
      {findings ? (
        // pre-wrap, because the inspector's line breaks separate observations.
        // Collapsed into one paragraph they read as a single rambling sentence.
        <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{findings}</p>
      ) : (
        <p className="ak-muted" style={{ margin: 0 }}>
          The inspector recorded no written findings.
        </p>
      )}

      {/* Stated either way. Rendering nothing when it is false would leave the
          admin unable to tell "not checked by a service centre" apart from "this
          screen does not show that". */}
      <p style={{ margin: '0.6rem 0 0', fontSize: '0.86rem' }}>
        {report?.checked_by_asc
          ? 'Checked by an authorised service centre (ASC).'
          : 'Not checked by an authorised service centre.'}
      </p>

      {/* Whose judgement the admin is confirming. Both fields are in the payload
          and were previously shown nowhere at all. */}
      <p className="ak-muted" style={{ margin: '0.3rem 0 0', fontSize: '0.82rem' }}>
        {who || when
          ? `Inspected by ${who || 'an unnamed user'}${when ? ` on ${when}` : ''}.`
          : 'No inspection has been submitted on this report yet.'}
      </p>
    </>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/InspectionSummary.jsx frontend/src/admin/components/incident/InspectionSummary.test.jsx
git commit -m "feat(incidents): show the admin what the inspection concluded

Findings with their line breaks intact, the ASC fact stated either way,
and who inspected it and when — the last two are in the payload and were
displayed nowhere."
```

---

### Task 4: `OutcomeSummary`

The most decision-relevant section on the admin's screen: Confirm applies every
one of these outcomes to stock in a single transaction.

**Files:**
- Modify: `frontend/src/admin/components/incident/InspectionSummary.jsx`
- Modify: `frontend/src/admin/components/incident/InspectionSummary.test.jsx`

- [ ] **Step 1: Write the failing test**

Change the `InspectionSummary` import line in the test file to:

```jsx
import { FindingsSummary, OutcomeSummary } from './InspectionSummary';
```

and append:

```jsx
/** One quarantined unit the inspector has decided to scrap. */
const scrappedItem = (over = {}) => ({
  item_id: 11,
  product_id: 7,
  product_name: 'Split Type 1.5HP',
  status: 'quarantined',
  outcome: 'scrap',
  outcome_note: 'compressor seized, casing intact',
  quantity: 1,
  unit_role: 'indoor',
  serial_number: 'SN-1',
  batch_id: 3,
  warranty_state: 'expired',
  supplier_warranty: { state: 'expired', end_date: '2025-01-01', supplier_name: 'Supplier A' },
  ...over,
});

describe('OutcomeSummary', () => {
  test('shows what the inspector decided and why', () => {
    render(<OutcomeSummary items={[scrappedItem()]} />);

    expect(screen.getByText('Scrap')).toBeInTheDocument();
    expect(screen.getByText(/compressor seized, casing intact/)).toBeInTheDocument();
    expect(screen.getByText(/Split Type 1.5HP/)).toBeInTheDocument();
    expect(screen.getByText(/SN-1/)).toBeInTheDocument();
  });

  // The single most important line on the admin's screen: Confirm is the one
  // and only moment stock moves.
  test('warns when confirming will deduct the unit', () => {
    render(<OutcomeSummary items={[scrappedItem()]} />);

    expect(screen.getByText(/Confirming deducts this unit from stock/i)).toBeInTheDocument();
  });

  test('does not warn for an outcome that keeps the goods', () => {
    render(<OutcomeSummary items={[scrappedItem({ outcome: 'repair' })]} />);

    expect(screen.getByText('Send for repair')).toBeInTheDocument();
    expect(screen.queryByText(/deducts this unit from stock/i)).not.toBeInTheDocument();
  });

  test('both warranty clocks are shown, because they disagree by design', () => {
    render(<OutcomeSummary items={[scrappedItem({
      warranty_state: 'none',
      supplier_warranty: { state: 'in_warranty', end_date: '2029-08-12', supplier_name: 'Supplier A' },
    })]} />);

    expect(screen.getByText('Not sold yet')).toBeInTheDocument();
    expect(screen.getByText('Supplier warranty active')).toBeInTheDocument();
  });

  test('calls out a unit the inspector left undecided', () => {
    render(<OutcomeSummary items={[scrappedItem({ outcome: null, outcome_note: null })]} />);

    expect(screen.getByText(/No outcome recorded/i)).toBeInTheDocument();
  });

  test('an already-settled unit reads as history', () => {
    render(<OutcomeSummary items={[scrappedItem({ status: 'scrapped' })]} />);

    expect(screen.getByText(/Settled as/i)).toBeInTheDocument();
  });

  test('says so when the report names no units', () => {
    render(<OutcomeSummary items={[]} />);

    expect(screen.getByText(/names no physical units/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: FAIL — `Element type is invalid` / `OutcomeSummary is not exported`.

- [ ] **Step 3: Implement it**

Add these imports at the top of `frontend/src/admin/components/incident/InspectionSummary.jsx`, below `import React from 'react';`:

```jsx
import {
  itemStatusMeta, outcomeLabel, outcomeRemovesStock, unitRoleLabel,
  supplierWarrantyDetail, supplierWarrantyMeta, warrantyStateMeta,
} from '../../utils/incidentItems';
import { formatPieces } from '../../utils/packageFormat';
```

Append to the same file:

```jsx
/** Every unit on the report and what the inspector decided about each. */
export function OutcomeSummary({ items }) {
  const rows = Array.isArray(items) ? items : [];

  if (rows.length === 0) {
    return <p style={{ margin: 0 }}>This report names no physical units.</p>;
  }

  return rows.map((item) => <UnitOutcomeSummary key={item.item_id} item={item} />);
}

/**
 * One unit, as the admin reads it.
 *
 * Deliberately mirrors ItemOutcomeRow's header and badges. The admin holds the
 * signed hardcopy the inspector printed from that same layout, and two
 * renderings that describe the same unit differently is how a mismatch gets
 * waved through.
 */
function UnitOutcomeSummary({ item }) {
  const status = itemStatusMeta(item.status);
  const warranty = warrantyStateMeta(item.warranty_state);
  const supplier = supplierWarrantyMeta(item.supplier_warranty?.state);
  const supplierDetail = supplierWarrantyDetail(item.supplier_warranty);
  const qtyLabel = formatPieces(item.quantity, item.pieces_per_package, item.package_unit);
  const settled = item.status !== 'quarantined';

  return (
    <fieldset className="incdoc__unit">
      <legend className="incdoc__unit-legend">
        {unitRoleLabel(item.unit_role)}{' '}
        <span className={`ak-badge ${status.badge}`}>{status.label}</span>
      </legend>

      <div style={{ fontSize: '0.86rem', marginBottom: '0.5rem' }}>
        <strong>{item.product_name || `#${item.product_id}`}</strong> · {qtyLabel}
        {item.batch_id ? ` · batch #${item.batch_id}` : ''}
        {item.model_code ? ` · model ${item.model_code}` : ''}
        {item.serial_number ? ` · SN ${item.serial_number}` : ''}
      </div>

      {/* Both clocks, because they disagree by design: stock on the shelf has
          supplier cover and no customer warranty at all. An admin reading only
          the customer badge would think an uncovered unit was being returned. */}
      {warranty && (
        <div style={{ marginBottom: '0.35rem' }}>
          <span className={`ak-badge ${warranty.badge}`}>{warranty.label}</span>
        </div>
      )}
      {supplier && (
        <div style={{ marginBottom: '0.5rem' }}>
          <span className={`ak-badge ${supplier.badge}`}>{supplier.label}</span>{' '}
          {supplierDetail && (
            <span className="ak-muted" style={{ fontSize: '0.8rem' }}>{supplierDetail}</span>
          )}
        </div>
      )}

      {item.outcome ? (
        <div style={{ fontSize: '0.86rem' }}>
          {settled ? 'Settled as ' : 'The inspector decided: '}
          <strong>{outcomeLabel(item.outcome)}</strong>
          {item.outcome_note ? ` — ${item.outcome_note}` : ''}

          {/* Said on the row, not in a footnote. Confirming is the one and only
              moment stock moves, and the person confirming is not the person who
              chose this. */}
          {!settled && outcomeRemovesStock(item.outcome) && (
            <div style={{ marginTop: '0.35rem' }}>
              <span className="ak-badge ak-badge--amber">
                Confirming deducts this unit from stock.
              </span>
            </div>
          )}
        </div>
      ) : (
        <div style={{ fontSize: '0.86rem' }}>
          <span className="ak-badge ak-badge--amber">No outcome recorded</span>{' '}
          <span className="ak-muted">
            The inspector left this unit undecided, so there is nothing to apply to it.
          </span>
        </div>
      )}
    </fieldset>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: PASS, 14 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/InspectionSummary.jsx frontend/src/admin/components/incident/InspectionSummary.test.jsx
git commit -m "feat(incidents): show the admin each unit's outcome before they confirm

Confirming applies every one of these in a single transaction, so the rows
say which of them deduct stock. Both warranty clocks are shown because they
disagree by design, and an undecided unit is called out rather than blank."
```

---

### Task 5: `SignatureSummary`

**Files:**
- Modify: `frontend/src/admin/components/incident/InspectionSummary.jsx`
- Modify: `frontend/src/admin/components/incident/InspectionSummary.test.jsx`

- [ ] **Step 1: Write the failing test**

Change the import line in the test file to:

```jsx
import { FindingsSummary, OutcomeSummary, SignatureSummary } from './InspectionSummary';
```

and append:

```jsx
describe('SignatureSummary', () => {
  test('shows both names and the date from the signed hardcopy', () => {
    render(<SignatureSummary report={{
      signed_by_representative: 'A. Reyes',
      signed_by_branch_manager: 'M. Santos',
      signed_at: '2026-08-13',
    }} />);

    expect(screen.getByText('A. Reyes')).toBeInTheDocument();
    expect(screen.getByText('M. Santos')).toBeInTheDocument();
    expect(screen.getByText('2026-08-13')).toBeInTheDocument();
  });

  // An unsigned form is a fact the admin needs, not an empty box to skim past.
  test('names each missing signature rather than leaving a gap', () => {
    render(<SignatureSummary report={{}} />);

    expect(screen.getAllByText(/Not recorded/i)).toHaveLength(3);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: FAIL — `SignatureSummary is not exported`.

- [ ] **Step 3: Implement it**

Append to `frontend/src/admin/components/incident/InspectionSummary.jsx`:

```jsx
/**
 * The two names on the hardcopy.
 *
 * The system records that signing happened; what is legally signed is the
 * printed sheet. The admin has that sheet in hand, and these three values are
 * what they check it against — so a blank box has to read "not recorded" rather
 * than looking like a field this screen forgot to render.
 */
export function SignatureSummary({ report }) {
  const lines = [
    ['Authorised representative', report?.signed_by_representative],
    ['Branch manager', report?.signed_by_branch_manager],
    ['Date signed', report?.signed_at],
  ];

  return (
    <div className="incdoc__row">
      {lines.map(([label, value]) => (
        <div key={label}>
          <span className="ak-label">{label}</span>
          <div>
            {value || <span className="ak-muted">Not recorded</span>}
          </div>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: PASS, 16 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/InspectionSummary.jsx frontend/src/admin/components/incident/InspectionSummary.test.jsx
git commit -m "feat(incidents): show the admin the signatures on the hardcopy

The admin holds the signed sheet and checks it against these three values,
so an unsigned line says 'not recorded' rather than rendering as a gap."
```

---

### Task 6: `EvidenceSummary`

The section the bug report was actually about.

**Files:**
- Modify: `frontend/src/admin/components/incident/InspectionSummary.jsx`
- Modify: `frontend/src/admin/components/incident/InspectionSummary.test.jsx`

- [ ] **Step 1: Write the failing test**

Change the import line in the test file to:

```jsx
import {
  EvidenceSummary, FindingsSummary, OutcomeSummary, SignatureSummary,
} from './InspectionSummary';
```

and append:

```jsx
describe('EvidenceSummary', () => {
  // A `defective` report asks for four requirements — see
  // evidenceRequirementsFor() in utils/incidentDocument.
  const defectiveEvidence = [
    { evidence_id: 1, requirement: 'defect_photo', photo_path: 'incidents/a.jpg', photo_source: 'captured' },
    { evidence_id: 2, requirement: 'defect_photo', photo_path: 'incidents/b.jpg', photo_source: 'uploaded' },
    { evidence_id: 3, requirement: 'model_serial_sticker', photo_path: 'incidents/c.jpg', photo_source: 'captured' },
    { evidence_id: 4, requirement: 'box_two_sides', photo_path: null, skipped_reason: 'the box was already discarded' },
  ];

  test('shows every requirement with its photos, skips and outstanding items', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="defective"
      checkedByAsc={false}
      returnReasonCode={null}
      evidence={defectiveEvidence}
    />);

    expect(screen.getByText('2 attached')).toBeInTheDocument();
    expect(screen.getByText('Skipped')).toBeInTheDocument();
    // A skip SATISFIES a requirement — the reason is the evidence.
    expect(screen.getByText(/the box was already discarded/)).toBeInTheDocument();
    // accessories_warranty was never answered.
    expect(screen.getByText('Outstanding')).toBeInTheDocument();

    // Three photos, three enlargeable thumbnails.
    expect(await screen.findAllByRole('button', { name: /enlarge/i })).toHaveLength(3);
  });

  test('an outstanding requirement says nothing was attached and no reason given', () => {
    render(<EvidenceSummary incidentId={5} reason="wrong_type" checkedByAsc={false} evidence={[]} />);

    expect(screen.getByText(/no reason was given/i)).toBeInTheDocument();
  });

  // Hiding it would read as a deleted file when it is still on the report.
  test('evidence against a requirement no longer asked for is still shown', () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="wrong_type"
      checkedByAsc={false}
      evidence={[{ evidence_id: 9, requirement: 'asc_service_report', photo_path: null, skipped_reason: 'no ASC nearby' }]}
    />);

    expect(screen.getByText(/Also attached: ASC service report/i)).toBeInTheDocument();
  });

  test('says so when the reason asks for no photographs', () => {
    render(<EvidenceSummary incidentId={5} reason="missing" checkedByAsc={false} evidence={[]} />);

    expect(screen.getByText(/asks for no photographs/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: FAIL — `EvidenceSummary is not exported`.

- [ ] **Step 3: Implement it**

Add these imports at the top of `frontend/src/admin/components/incident/InspectionSummary.jsx`:

```jsx
import { EvidenceThumbnail } from './EvidencePhoto';
import { requirementMeta } from './EvidenceChecklist';
import { requirementsFor } from '../../utils/incidentDocument';
```

`requirementMeta` is a pure label lookup, and `sections/AttachmentsSection.jsx:4` already imports it from the same place — follow that precedent rather than moving it.

Append to the file:

```jsx
/**
 * The evidence checklist as the ADMIN reads it — the same requirements the
 * inspector answered, with no way to add, skip or remove anything.
 *
 * This is the section the whole change exists for: it rendered completely
 * empty, so an admin approving a write-off could not see a single photograph of
 * the unit being written off.
 *
 * `checkedByAsc` must come from the SAVED report rather than any local state.
 * Ticking the ASC box is what adds the service report to the required list, and
 * the admin is reading what was submitted, not composing it.
 */
export function EvidenceSummary({ incidentId, reason, checkedByAsc, returnReasonCode, evidence }) {
  const required = requirementsFor(reason, checkedByAsc, returnReasonCode);
  const rows = Array.isArray(evidence) ? evidence : [];
  const extra = rows.filter((e) => !required.includes(e.requirement));

  if (required.length === 0 && extra.length === 0) {
    return <p style={{ margin: 0 }}>This type of report asks for no photographs.</p>;
  }

  return (
    <div className="ak-field" style={{ gap: '0.8rem' }}>
      {required.map((req) => (
        <RequirementSummary
          key={req}
          incidentId={incidentId}
          requirement={req}
          attached={rows.filter((e) => e.requirement === req)}
        />
      ))}

      {/* Attached against a requirement this reason no longer asks for — an ASC
          report attached before the tick was cleared, say. Hiding it would look
          like the file had been deleted when it is still on the report. */}
      {extra.map((e) => (
        <RequirementSummary
          key={`extra-${e.evidence_id}`}
          incidentId={incidentId}
          requirement={e.requirement}
          attached={[e]}
          alsoAttached
        />
      ))}
    </div>
  );
}

/**
 * One checklist line, read-only.
 *
 * Three states the admin must be able to tell apart: photographed, skipped with
 * a written reason, and never answered. A SKIP SATISFIES A REQUIREMENT — that
 * is the point of recording one, so the admin sees the box photo is missing
 * BECAUSE the box was discarded rather than seeing a thin report and guessing.
 */
function RequirementSummary({ incidentId, requirement, attached, alsoAttached = false }) {
  const meta = requirementMeta(requirement);
  const photos = attached.filter((e) => e.photo_path);
  const skips = attached.filter((e) => !e.photo_path);
  const satisfied = attached.length > 0;
  const skippedOnly = satisfied && photos.length === 0;

  return (
    <fieldset
      className="ak-card"
      style={{ border: '1px solid var(--ak-line, #ddd)', padding: '0.75rem', margin: 0 }}
    >
      <legend className="ak-label" style={{ padding: '0 0.35rem' }}>
        {alsoAttached ? `Also attached: ${meta.label}` : meta.label}{' '}
        {satisfied ? (
          <span className={`ak-badge ${skippedOnly ? 'ak-badge--gray' : 'ak-badge--green'}`}>
            {skippedOnly ? 'Skipped' : `${photos.length} attached`}
          </span>
        ) : (
          <span className="ak-badge ak-badge--amber">Outstanding</span>
        )}
      </legend>

      {!satisfied && (
        <div className="ak-muted" style={{ fontSize: '0.82rem' }}>
          Nothing was attached against this requirement, and no reason was given.
        </div>
      )}

      {photos.length > 0 && (
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'center' }}>
          {photos.map((e) => (
            <EvidenceThumbnail
              key={e.evidence_id}
              incidentId={incidentId}
              evidenceId={e.evidence_id}
              enlargeable
              alt={meta.label}
            />
          ))}
        </div>
      )}

      {skips.map((e) => (
        <div key={e.evidence_id} style={{ fontSize: '0.85rem' }}>
          <strong>Skipped:</strong> {e.skipped_reason}
        </div>
      ))}
    </fieldset>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: PASS, 20 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/InspectionSummary.jsx frontend/src/admin/components/incident/InspectionSummary.test.jsx
git commit -m "feat(incidents): show the admin the evidence they are deciding on

The section they most needed rendered completely empty — an admin approving
a write-off could not see one photograph of the unit. Photographed, skipped
with a reason, and never answered are told apart, because a skip satisfies a
requirement and a silence does not."
```

---

### Task 7: Wire the summaries into `decide` mode

**Files:**
- Modify: `frontend/src/admin/components/incident/IncidentDocument.jsx:575-627`
- Modify: `frontend/src/admin/components/incident/sections/AttachmentsSection.jsx:35`
- Modify: `frontend/src/admin/components/incident/sections/FindingsSection.jsx:20`
- Modify: `frontend/src/admin/components/incident/sections/OutcomesSection.jsx:27`
- Modify: `frontend/src/admin/components/incident/sections/SignaturesSection.jsx:21`
- Test: `frontend/src/admin/components/incident/IncidentDocument.test.jsx`

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/admin/components/incident/IncidentDocument.test.jsx`:

```jsx
/**
 * A report the inspector has finished with, waiting on the admin.
 *
 * The decide-mode test above asserts only on CSS classes, which is why four
 * empty sections shipped. These assert on what an admin can actually read.
 */
const inspectedReport = () => filedReport({
  status_name: 'Awaiting Inspection',
  inspection_findings: 'Compressor seized. Casing intact.',
  checked_by_asc: true,
  inspected_by_name: 'Rita Cruz',
  inspected_at: '2026-08-13T09:15:00+08:00',
  signed_by_representative: 'A. Reyes',
  signed_by_branch_manager: 'M. Santos',
  signed_at: '2026-08-13',
  evidence: [
    { evidence_id: 1, requirement: 'defect_photo', photo_path: 'incidents/a.jpg', photo_source: 'captured' },
    { evidence_id: 4, requirement: 'box_two_sides', photo_path: null, skipped_reason: 'the box was already discarded' },
  ],
  items: [{
    ...filedReport().items[0],
    outcome: 'scrap',
    outcome_note: 'compressor seized, casing intact',
  }],
});

test('decide mode shows the admin the whole inspection, not four empty sections', async () => {
  await renderDoc({ mode: 'decide', report: inspectedReport() });

  // Findings, and whose judgement this is.
  expect(screen.getByText(/Compressor seized\. Casing intact\./)).toBeInTheDocument();
  expect(screen.getByText(/Inspected by Rita Cruz/)).toBeInTheDocument();

  // The outcome that Confirm will apply, and that it moves stock.
  expect(screen.getByText('Scrap')).toBeInTheDocument();
  expect(screen.getByText(/Confirming deducts this unit from stock/i)).toBeInTheDocument();

  // The evidence, including why one requirement has no photo.
  expect(screen.getByText(/the box was already discarded/)).toBeInTheDocument();

  // The names on the hardcopy in their hand.
  expect(screen.getByText('A. Reyes')).toBeInTheDocument();
  expect(screen.getByText('M. Santos')).toBeInTheDocument();
});

test('decide mode gives the admin nothing to write in below the rule', async () => {
  await renderDoc({ mode: 'decide', report: inspectedReport() });

  // setOutcome is `inspect`-gated and decide is `resolve`-gated: a control here
  // would 403 rather than save.
  expect(screen.queryByLabelText(/what happens to this unit/i)).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /can.t provide this/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^remove$/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /submit inspection/i })).not.toBeInTheDocument();
});

test('file mode still greys the inspector half rather than summarising it', async () => {
  await renderDoc();

  // The reporter sees what WILL be asked, so they keep the box instead of
  // throwing it away. The ternary must not swallow this fallback.
  expect(screen.getByText(/Picture of the defect/i)).toBeInTheDocument();
});
```

Also add `evidencePhotoBlob` handling to the existing `beforeEach` in that file, since decide mode now fetches evidence photos:

```jsx
beforeEach(() => {
  locationsAPI.getAll.mockResolvedValue({ data: [{ location_id: 1, location_name: 'Carmen' }] });
  productsAPI.getAll.mockResolvedValue({ data: [] });
  inventoryBatchesAPI.getAll.mockResolvedValue({ data: [] });
  incidentReportsAPI.evidencePhotoBlob.mockResolvedValue(new Blob(['x']));
  incidentReportsAPI.photoBlob.mockResolvedValue(new Blob(['x']));
  global.URL.createObjectURL = jest.fn(() => 'blob:fake-url');
  global.URL.revokeObjectURL = jest.fn();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "IncidentDocument"
```

Expected: FAIL — `Unable to find an element with the text: /Compressor seized/`. The sections are empty.

- [ ] **Step 3: Fix the four `??` operators**

`false ?? fallback` is `false`. Any child gated on a boolean silently erases the fallback, which is the trap that caused this bug. `||` treats `false` as absent, which is what every one of these call sites means.

In `frontend/src/admin/components/incident/sections/AttachmentsSection.jsx` line 35, change `{children ?? (` to `{children || (`.

In `frontend/src/admin/components/incident/sections/FindingsSection.jsx` line 20, change `{children ?? <p style={{ margin: 0 }}>Not inspected yet.</p>}` to `{children || <p style={{ margin: 0 }}>Not inspected yet.</p>}`.

In `frontend/src/admin/components/incident/sections/OutcomesSection.jsx` line 27, change `{children ?? (` to `{children || (`.

In `frontend/src/admin/components/incident/sections/SignaturesSection.jsx` line 21, change `{children ?? (` to `{children || (`.

- [ ] **Step 4: Import the summaries in `IncidentDocument.jsx`**

Add below the existing `import { FindingsFields, OutcomeRows, SignatureFields } from './InspectionFields';` (line 16):

```jsx
import {
  EvidenceSummary, FindingsSummary, OutcomeSummary, SignatureSummary,
} from './InspectionSummary';
```

- [ ] **Step 5: Switch the four children on mode**

In `frontend/src/admin/components/incident/IncidentDocument.jsx`, replace lines 575-627 (the four sections from `{has(SECTION.ATTACHMENTS) && (` through the close of the signatures block) with:

```jsx
      {has(SECTION.ATTACHMENTS) && (
        <AttachmentsSection
          state={state(SECTION.ATTACHMENTS)}
          reason={form.reason}
          returnReasonCode={form.returnReasonCode}
        >
          {/* Three renderings, one section. `file` passes nothing and gets the
              greyed preview of what will be asked; `inspect` gets the checklist
              that writes; `decide` gets the same evidence, read-only. */}
          {inspecting && report ? (
            <EvidenceChecklist
              incidentId={incidentId}
              reason={report.reason}
              checkedByAsc={checkedByAsc}
              returnReasonCode={report.return_reason_code}
              evidence={evidence}
              onAdded={handleEvidenceAdded}
              onRemoved={handleEvidenceRemoved}
              disabled={inspectorLocked}
            />
          ) : deciding && report ? (
            <EvidenceSummary
              incidentId={incidentId}
              reason={report.reason}
              // The SAVED tick, not the local one. The admin reads what was
              // submitted; they are not composing it.
              checkedByAsc={!!report.checked_by_asc}
              returnReasonCode={report.return_reason_code}
              evidence={evidence}
            />
          ) : null}
        </AttachmentsSection>
      )}

      {has(SECTION.FINDINGS) && (
        <FindingsSection state={state(SECTION.FINDINGS)}>
          {inspecting ? (
            <FindingsFields
              findings={findings} setFindings={setFindings}
              checkedByAsc={checkedByAsc} setCheckedByAsc={setCheckedByAsc}
              disabled={inspectorLocked}
            />
          ) : deciding && report ? (
            <FindingsSummary report={report} />
          ) : null}
        </FindingsSection>
      )}

      {has(SECTION.OUTCOMES) && (
        <OutcomesSection state={state(SECTION.OUTCOMES)} units={form.units}>
          {inspecting && report ? (
            <OutcomeRows items={items} disabled={inspectorLocked} onSave={handleOutcome} />
          ) : deciding && report ? (
            <OutcomeSummary items={items} />
          ) : null}
        </OutcomesSection>
      )}

      {has(SECTION.SIGNATURES) && (
        <SignaturesSection state={state(SECTION.SIGNATURES)}>
          {inspecting ? (
            <SignatureFields
              signedByRep={signedByRep} setSignedByRep={setSignedByRep}
              signedByBm={signedByBm} setSignedByBm={setSignedByBm}
              signedAt={signedAt} setSignedAt={setSignedAt}
              disabled={inspectorLocked}
            />
          ) : deciding && report ? (
            <SignatureSummary report={report} />
          ) : null}
        </SignaturesSection>
      )}
```

Each ternary yields `null` in `file` mode, and `null || fallback` renders the greyed preview — so the reporter still sees what will be asked of the inspector.

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "IncidentDocument"
```

Expected: PASS, including the pre-existing tests.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/components/incident/IncidentDocument.jsx frontend/src/admin/components/incident/IncidentDocument.test.jsx frontend/src/admin/components/incident/sections/
git commit -m "fix(incidents): the admin's decision screen was rendering four empty sections

The sections below the rule take their content as \`children\` gated on
\`inspecting\`, so in decide mode each child was the value \`false\` — and
\`false ?? fallback\` is \`false\`, which React renders as nothing. No evidence,
no findings, no outcomes, no signatures. The admin was asked to press
Confirm, the one action that moves stock, without seeing any of it.

The four gates now switch on mode, and the \`??\` becomes \`||\` so a boolean
child can never silently erase a fallback again.

The suite missed this because the decide-mode test asserted only that each
section carried the readonly class, never that anything was inside it."
```

---

### Task 8: Let the reporter's photo enlarge too

Both kinds of photo should behave alike; an admin who learns to click one will
click the other.

**Files:**
- Modify: `frontend/src/admin/components/incident/ReportPhoto.jsx:63-75`
- Test: `frontend/src/admin/components/incident/InspectionSummary.test.jsx`

- [ ] **Step 1: Write the failing test**

Add to the import block at the **top** of `frontend/src/admin/components/incident/InspectionSummary.test.jsx`:

```jsx
import ReportPhoto from './ReportPhoto';
```

Then append the tests to the same file:

```jsx
describe('ReportPhoto', () => {
  test('the reporter’s photo opens full-size like the evidence does', async () => {
    render(<ReportPhoto incidentId={5} photoPath="incidents/2026/08/abc.jpg" photoSource="captured" />);

    fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));

    expect(await screen.findByRole('dialog')).toBeInTheDocument();
  });

  test('says so when nothing was attached at filing', () => {
    render(<ReportPhoto incidentId={5} photoPath={null} />);

    expect(screen.getByText(/No photo was attached at filing/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: FAIL — no button with an accessible name matching `/enlarge/i`.

- [ ] **Step 3: Implement it**

In `frontend/src/admin/components/incident/ReportPhoto.jsx`, add to the imports:

```jsx
import PhotoLightbox from './PhotoLightbox';
```

Add a third state alongside the existing two:

```jsx
  const [open, setOpen] = useState(false);
```

Replace the `{url ? (<img … />) : (…)}` block (lines 63-75) with:

```jsx
      {url ? (
        <>
          <button
            type="button"
            title="Photo filed with the report — click to enlarge"
            onClick={() => setOpen(true)}
            style={{ background: 'none', border: 'none', padding: 0, cursor: 'zoom-in', lineHeight: 0 }}
          >
            <img
              src={url}
              alt="Filed with the incident report"
              style={{ maxWidth: '100%', maxHeight: 320, borderRadius: 8, border: '1px solid var(--ak-line)' }}
            />
          </button>
          {open && (
            <PhotoLightbox url={url} alt="Filed with the incident report" onClose={() => setOpen(false)} />
          )}
        </>
      ) : (
        // The raw storage path is meaningless to a reader, and reads identically
        // whether the fetch is in flight or has failed.
        <span className="ak-muted">
          {failed ? 'Photo attached — it could not be loaded.' : 'Loading photo…'}
        </span>
      )}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd frontend
npx react-scripts test --watchAll=false --testPathPattern "InspectionSummary"
```

Expected: PASS, 22 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/incident/ReportPhoto.jsx frontend/src/admin/components/incident/InspectionSummary.test.jsx
git commit -m "feat(incidents): the reporter's photo enlarges like the evidence does

Two kinds of photo on one document behaving differently is a small trap:
an admin who learns to click one will click the other."
```

---

### Task 9: Verify the whole suite and the real screen

**Files:** none modified unless a regression turns up.

- [ ] **Step 1: Run the full frontend suite**

```bash
cd frontend
npx react-scripts test --watchAll=false
```

Expected: PASS. If anything outside `incident/` fails, it is unrelated to this work — check `git stash` against `main` before chasing it.

- [ ] **Step 2: Run the backend suite**

Nothing here touched PHP, so this is a guard against an unrelated break sneaking into the same branch.

```bash
cd back-end
php artisan test --filter=Incident
```

Expected: PASS.

- [ ] **Step 3: Check the real screen**

```bash
cd frontend
npm run dev
```

Sign in as a user holding `incident_reports.resolve`, open the incident list, and click into a report that has been inspected. Confirm on screen:

- the Attachments section lists each requirement with its photos, and a thumbnail opens full-size on click
- a skipped requirement shows the written reason
- the Findings section shows the text, the ASC line, and "Inspected by …"
- each unit shows its outcome, and a scrap/write-off/return says "Confirming deducts this unit from stock."
- the Signatures section shows both names and the date
- nothing below the rule is editable — no file inputs, no dropdowns, no Remove buttons

- [ ] **Step 4: Commit anything the manual check turned up**

If steps 1-3 were clean there is nothing to commit and this task is done.

---

## Known follow-up, deliberately not in this plan

A submitted inspection stays in the inspector's queue wearing a blue "Sent back"
badge, because `Awaiting Inspection` is written by BOTH
`IncidentService::submitInspection()` (line 244, inspector submitted, waiting on
the admin) and `IncidentService::reject()` (line 363, admin sent it back,
waiting on the inspector). `IncidentInspectionPage.jsx:32` filters the queue on
`['Open', 'Awaiting Inspection']`, so a report the inspector just submitted is
indistinguishable from one the admin rejected.

It compounds with the bug this plan fixes: an admin who cannot see the
inspection reasonably rejects it, which returns it to exactly the status a fresh
submission already sits at. Worth fixing before this flow is used in earnest,
but it needs its own spec — it is a status-model change, not a rendering one.
