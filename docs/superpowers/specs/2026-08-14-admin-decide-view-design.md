# The admin's decision view shows the whole document

**Date:** 2026-08-14
**Status:** Approved, ready for planning

## The problem

An admin opening an incident report to decide it sees the reporter's half in
full and the inspector's half **entirely blank** — no evidence photos, no
findings, no per-unit outcomes, no signatures. They are asked to press Confirm,
the one action that moves stock, without seeing any of the work they are
confirming.

### Root cause

`IncidentDocument.jsx` passes the inspector's content into the four sections
below the rule as `children`, each gated on `inspecting`:

| Line | Section | Children expression |
|------|---------|---------------------|
| 581 | Attachments | `{inspecting && report && <EvidenceChecklist/>}` |
| 598 | Findings | `{inspecting && <FindingsFields/>}` |
| 610 | Outcome per unit | `{inspecting && report && <OutcomeRows/>}` |
| 618 | Signatures | `{inspecting && <SignatureFields/>}` |

In `decide` mode `inspecting === false`, so each `children` is the value
`false`. Every section then renders `{children ?? fallback}` — and `??` falls
back only on `null`/`undefined`. **`false ?? fallback` is `false`**, which React
renders as nothing. Four headings, four empty bodies.

This is unfinished work, not a regression. `InspectionFields.jsx:14` states the
fields were split out "so the same sections can take a read-only rendering in
`decide` mode without either version growing a mode branch inside it", and
`FindingsSection.jsx:8` still reads "Task 10 passes the recorded text,
read-only". Those read-only renderings were never written.

### Why the suite did not catch it

`IncidentDocument.test.jsx:70` — "decide mode leaves every section on the page
and none of it writable" — passes against the blank sections. It asserts only
that each section carries `incdoc__section--readonly`, never that anything is
inside it. The bug was invisible to the tests by construction, and the new tests
below assert on content for that reason.

### What already works

The reporter's half hydrates correctly through `formFromReport()`, including the
reporter's context photo via `ReportPhoto` (`ProblemSummarySection.jsx:62`). The
gap is specifically everything below the rule.

An admin can currently see the missing information only by clicking "Print
incident report", which builds the full document as an HTML string in
`utils/incidentReportDocument.js`. That is a workaround, not a design.

## Constraints that shape the fix

- `POST /incident-reports/{id}/items/{itemId}/outcome` is gated on
  `incident_reports.inspect` (`routes/api.php:617`), while `decide` is gated on
  `resolve` (line 620). An admin holding `resolve` + `read` **cannot** change an
  outcome. The admin's view of the outcomes is therefore read-only by necessity,
  not merely by choice; their lever is Reject-with-a-note.
- `GET /incident-reports/{id}/evidence/{evidenceId}/photo` is already gated on
  `read` (`routes/api.php:611-615`), with the comment "the admin reviewing a
  submission needs to see the photos, and holds `read`, not `inspect`". The
  backend was built for this screen.
- The print path is a string builder, not React components. There is no existing
  read-only rendering to reuse.

## Scope

Frontend rendering only. **No backend change, no migration, no API change.**
`GET /incident-reports/{id}` already returns `evidence`, `items` with their
outcomes, `inspection_findings`, `checked_by_asc`, the signature fields,
`inspected_by_name` and `inspected_at`.

`decide` mode is used by exactly one caller, `IncidentResolveDrawer.jsx:69`.

### Explicitly out of scope

- A completeness summary banner at the top of the admin's view. The
  per-requirement badges already carry that information.
- The separate queue/status problem, where a submitted inspection stays in the
  inspector's queue because `Awaiting Inspection` means both "submitted, waiting
  on the admin" and "rejected, waiting on the inspector". That is its own fix.

## Design

### Component boundaries

**New — `frontend/src/admin/components/incident/InspectionSummary.jsx`**

The read-only mirror of `InspectionFields.jsx`, exporting four pure
presentational components:

| Export | Props | Renders |
|--------|-------|---------|
| `EvidenceSummary` | `incidentId, reason, checkedByAsc, returnReasonCode, evidence` | requirement list, thumbnails, skip reasons |
| `FindingsSummary` | `report` | findings text, ASC fact, who inspected and when |
| `OutcomeSummary` | `items` | per-unit decision, warranty, stock consequence |
| `SignatureSummary` | `report` | the two names and the date |

None of them import the mutating members of `incidentReportsAPI` or `toast`. The
admin's view holds no write path to reach by accident — which matters, because
`setOutcome` would 403 for a `resolve`-only admin regardless.

**Moved — `EvidenceThumbnail`, from `EvidenceChecklist.jsx` to
`frontend/src/admin/components/incident/EvidencePhoto.jsx`**

It is now shared by both halves of the document. Leaving it inside the upload
component would make the read-only summary import from the editable UI.
`EvidenceChecklist` imports it from the new home. This is the only refactor in
this work, and the new shared use is what justifies it.

**New — `frontend/src/admin/components/incident/PhotoLightbox.jsx`**

A small overlay taking an already-loaded object URL and an alt string.

**Modified — `IncidentDocument.jsx`**

The four children expressions become mode ternaries:

```jsx
{inspecting ? <FindingsFields …/> : deciding ? <FindingsSummary report={report}/> : null}
```

**Modified — `sections/AttachmentsSection.jsx`, `FindingsSection.jsx`,
`OutcomesSection.jsx`, `SignaturesSection.jsx`**

`{children ?? fallback}` becomes `{children || fallback}`. Defensive: the
ternaries above make the sections' own fallbacks unreachable in `decide`, but a
`??` that swallows `false` is a trap for the next person to pass a gated child.

### What each summary shows

The governing rule, and the lesson of this bug: **every summary states an
absence in words; none of them renders empty.** "No outcome was recorded on this
unit" is information. A blank is not.

**Evidence.** Per requirement from `requirementsFor()`: the label, a badge
(`N attached` green / `Skipped` gray / `Outstanding` amber), clickable
thumbnails, and the written skip reason in full. Evidence attached against a
requirement the reason no longer asks for still appears as "Also attached", as
the checklist does — hiding it would read as a deleted file. Where the reason
asks for no photographs at all, it says so. `checkedByAsc` is read from
`report.checked_by_asc`, not from component state.

**Findings.** The findings text with line breaks preserved
(`white-space: pre-wrap`), or a sentence saying none were recorded. The ASC fact
stated either way, not only when true. Then `inspected_by_name` and
`inspected_at` — both are in the payload and currently shown nowhere, and the
admin should know whose judgement they are confirming.

**Outcome per unit.** The same unit header as `ItemOutcomeRow` — role via
`unitRoleLabel`, product name, quantity via `formatPieces`, batch, model code,
serial — then both warranty badges (customer and supplier), then the recorded
outcome via `outcomeLabel` with its note. Where `outcomeRemovesStock(outcome)`
is true, the row says **"Confirming deducts this unit from stock."** That is the
single most decision-relevant line on the admin's screen. A unit with no outcome
gets an amber callout rather than a gap. An already-settled item reads "Settled
as X", matching `ItemOutcomeRow`'s wording.

**Signatures.** Both names and the signing date, or "not recorded" for each.

### The lightbox

`EvidenceThumbnail` already fetches the private-disk blob and holds the object
URL. Given `enlargeable`, it wraps its `<img>` in a `<button>` and owns its own
lightbox open state, passing through the URL it already has — **no second
fetch**, and the URL stays under the existing unmount-revoke cleanup. The
overlay closes on backdrop click and on Escape.

A thumbnail whose fetch failed stays a plain "Photo attached — it could not be
loaded." message and is not clickable.

`ReportPhoto` gets the same click-to-enlarge treatment, so the reporter's
context photo and the inspector's evidence behave alike.

## Error handling

- A failed photo fetch already degrades to a sentence in both `ReportPhoto` and
  `EvidenceThumbnail`; that behaviour is preserved and the failed image is not
  clickable.
- Every null or empty field renders as a stated absence, per the rule above.
- No `??` guarding a child that may legitimately be `false`.

## Testing

Content assertions, not class assertions — `IncidentDocument.test.jsx:70` is the
counter-example this suite exists to avoid repeating.

1. Decide mode, fully inspected report: the findings text, the recorded outcome
   and its note, both signature names, and each satisfied requirement are all on
   screen.
2. A skipped requirement shows its written reason; an outstanding one shows the
   amber badge.
3. Decide mode renders no editable control below the rule — no file input, no
   outcome `<select>`, no Remove button, no "Can't provide this" button.
4. Absences read as sentences: a report with null findings says so rather than
   rendering blank; a unit with no outcome is called out.
5. `inspected_by_name` and `inspected_at` appear in the findings summary.
6. Clicking a thumbnail opens the lightbox and issues no second
   `evidencePhotoBlob` call.
7. Inspect mode is unchanged — the existing inspect-mode tests continue to pass.
