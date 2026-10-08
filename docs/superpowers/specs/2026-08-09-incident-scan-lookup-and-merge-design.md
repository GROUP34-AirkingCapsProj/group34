# Incident Scan Lookup + Report Incident Merge — Design

**Date:** 2026-08-09
**Branch:** backupV3
**Status:** Approved, ready for planning
**Builds on:** `2026-08-03-standalone-incident-reporting-design.md`, `2026-08-09-incident-camera-capture-design.md`

## Problem

Two things, related by the same root cause: identifying *which* product an incident is about is harder than it should be.

1. **No way to scan.** Filing an incident means finding the product in a dropdown of ~1000 items. On a phone at the shelf that is slow and error-prone, and it cannot identify a specific serialised unit at all — a filer can say "this aircon model" but not "this exact unit".

2. **Two features that overlap sit apart.** Barcode Adjustment corrects a mis-scanned receiving line and produces a `ScanCorrection`. An incident resolved as `wrong_type` produces *the same artifact*. A user correcting a wrongly-named product has no signal which tool to use.

## Goals

1. Scan a barcode — or type one, or pick from a list — to identify the product and, where applicable, the exact serial.
2. Put incident reporting where scanner users already work, under one name that covers both.
3. Preserve every existing capability: receiving corrections, the review queue, and the analyst's access.

## Non-goals

- Rewriting the receiving-correction flow (1342 lines handling quantity, serials, duplicate scans and voiding).
- Changing `IncidentService`, any resolution strategy, or the resolve drawer.
- Renaming the `/admin/barcode-adjustment` route. The page *title* is what users read; renaming the route breaks bookmarks for nothing.
- Merging the two flows into a single funnel — see "Why not one funnel".

## Decisions

| Decision | Choice |
|---|---|
| Structure | One page, two entry cards (not one merged funnel, not tabs) |
| Sidebar `Incident Reports` | **Kept** — the inventory analyst cannot reach Scan Barcode |
| Adjust tile | Renamed **Report Incident** |
| Serial resolution | Finds serials in **any** state, and reports that state |
| Manual entry | First-class, never removed |
| Scan behaviour | **Sets** the product; does not lock it |

### Why the sidebar entry must stay

This was nearly a serious mistake. Verified against the seeded permissions:

```
inventory_analyst  barcode_inventory_processing.create   --denied--
inventory_analyst  incident_reports.create               GRANTED
inventory_analyst  incident_reports.read                 GRANTED
inventory_analyst  incident_reports.resolve              GRANTED
```

The inventory analyst **cannot reach the Scan Barcode page at all**, yet is one of only two roles that can *resolve* an incident. Routing incidents solely through the scan page would have locked the primary reviewer out of the feature and left every report sitting Open forever.

So there are two doors into one feature, serving audiences with different access — not duplication:

| Role | Route in | Why |
|---|---|---|
| Warehouse personnel | Scan Barcode → Report Incident (also the sidebar) | Works from the scanner |
| Inventory analyst | Sidebar → Incident Reports | Denied Scan Barcode entirely |
| Branch manager | Sidebar | Files, cannot resolve |
| Admin | Either | Both |

Both doors open the **same** `IncidentFileModal`, so there is one form, one reason list, one camera, one scan field.

### Why not one funnel

A single "scan, then say what's wrong" flow was considered and rejected. Receiving corrections are keyed to a specific `receiving_detail_id`, and one barcode can appear across many receiving lines — so that branch must ask "which receiving?" after the scan regardless. The unified funnel would be a promise the data model cannot keep, at the cost of restructuring working code.

## Navigation

**`BarcodeScan.jsx`** — the mode tile keyed `adjust` is relabelled from `Adjust` to **Report Incident**. Its red warning-triangle icon already reads as "something is wrong" and stays. It still navigates to `/admin/barcode-adjustment`.

**`BarcodeAdjustmentPage`** — header changes from *"Inventory Adjustment"* to *"Report Incident"*, and the page opens with two permission-gated cards:

| Card | Gate | Behaviour |
|---|---|---|
| **Something's wrong with stock** | `incident_reports.create` | Opens `IncidentFileModal` |
| **Correct a receiving scan** | as today | The existing PO-line flow, unchanged |

A user with only one permission sees only that card. A user with neither sees the page's existing empty/denied state.

**Sidebar** — `Incident Reports` at `/admin/incidents` is unchanged, including its own Report Incident button.

## The lookup endpoint

`GET /api/incident-reports/lookup?barcode=<raw>&location_id=<id>`, gated on `incident_reports.create`.

Reuses the existing `resolveScan()` in the `NormalizesBarcode` trait — the same 3-pass resolution the issuance scanner and `FifoPickingService` use, so a barcode that works at the shelf works here — **with one deliberate difference**.

`resolveScan()` matches serials through `ItemSerial::issuableAt()`, which requires the unit to be in stock. For incidents that is backwards: a filer is often reporting a unit *because* it is damaged, missing, or already issued. The incident lookup therefore falls back to an unfiltered serial match and returns the unit's current state.

⚠️ **Do NOT change `resolveScan()` itself.** It is the shared resolver for `IssuanceScanController` and `FifoPickingService`; loosening its serial matching would let the pickers accept units that are not actually issuable, silently breaking FIFO enforcement. Add a **separate** method — e.g. `resolveScanAnyState()` — that tries `resolveScan()` first and only then falls back to an unfiltered `ItemSerial` lookup. The existing callers must keep the strict behaviour untouched.

`IncidentReportController` does not currently `use NormalizesBarcode`; it needs the trait added. `resolveScan()` is `protected`, so a controller using the trait can call it directly.

**Response on a hit:**

```json
{
  "success": true,
  "data": {
    "matched_by": "serial",
    "product": { "product_id": 12, "product_name": "...", "product_code": "...", "barcode": "..." },
    "serial": { "serial_id": 4471, "serial_number": "SN-4471", "status_label": "Issued", "in_stock": false },
    "location_quantity": 8,
    "batches": [ { "batch_id": 31, "zone_label": "Zone 1", "quantity_remaining": 5, "received_at": "..." } ]
  }
}
```

`matched_by` is `serial` or `product`. `serial` is null when a product barcode matched. `batches` lets the batch field collapse to a short, informed list instead of a dropdown of everything.

**On no match:** HTTP 404 with `{"success": false, "message": "No product matches that barcode at this location."}` — the client keeps the manual picker visible rather than dead-ending.

**Location scoping:** the endpoint resolves within the caller's visible locations using the same `forUser()` scope every other list uses, so nobody resolves a barcode at a location they cannot see. If no location is chosen yet, the client prompts for one rather than searching globally.

## The form

Scanning lives **inside `IncidentFileModal`**, so both doors get it and nothing is duplicated.

The product field becomes three ways to identify one thing, ordered by how people will reach for them:

```
Product *
[ 📷 Scan ]  [ barcode or serial…        ] [Find]
             or choose from the list ▾
```

1. **Scan** — reuses `CameraCapture`'s camera permission and stream-teardown handling, reading a barcode rather than taking a still.
2. **Type** — the same lookup, for a barcode that is readable but not scannable.
3. **Choose from the list** — the existing filterable picker, unchanged.

The third is not defensive padding. **Theft and damage are frequently why no barcode remains.** A report must never be blocked by a missing label, so the manual picker is always present and requires no barcode at all.

**On a hit:** the product is filled in; if a serial matched it is pinned to the report with its current state shown (*"SN-4471 — Issued 3 days ago"*), which is precisely the context a reviewer needs on a theft report. Batches narrow to the returned list.

**Scanning sets the product; it does not lock it.** Locking would be defensible for provenance, but it would strand anyone whose barcode is mislabelled — which is itself `wrong_type`, one of the eight reasons.

## Error handling

| Case | Behaviour |
|---|---|
| No match | 404, honest message, manual picker stays visible |
| No location chosen | Client prompts for location before searching; no global search |
| Camera unavailable | The existing `cameraAccessErrorMessage` wording, plus the type/pick fallbacks |
| Serial found but not in stock | Resolves normally, state shown — this is expected, not an error |
| Barcode matches multiple products | Return the first per existing `resolveScan` precedence; the filer can override via the picker |

## Testing

**Backend** — new cases for the lookup:
- A product barcode resolves and returns location quantity plus batches
- A serial resolves **in any state**, with its state reported (the deliberate divergence from `issuableAt`)
- An unknown barcode returns 404 with the documented shape
- Location scoping holds: a barcode at another location does not resolve
- `incident_reports.create` is required (auditor denied)

**Frontend** — no test suite; verified by the manual pass: scan a product barcode, scan a serial that is already issued, type a barcode, and file with no barcode at all via the picker.

## Related

- Touches `BarcodeScan.jsx` (tile label), `BarcodeAdjustmentPage.jsx` (header + two cards), `IncidentFileModal.jsx` (scan field), `IncidentReportController` (lookup), `NormalizesBarcode` (any-state serial fallback), `routes/api.php`.
- Does **not** touch `IncidentService`, `App\Services\Incidents\*`, `IncidentResolveDrawer`, or the receiving-correction submit path.

## Environment notes for the implementer

- Schema is a **SQL dump, not a migration history** — no migration is needed for this feature, but do not run bare `php artisan migrate`.
- Tests use `DatabaseTransactions`, never `RefreshDatabase`.
- The backend suite has **10 known pre-existing failures** (9 `WarrantyClaimTest`, 1 `SerialRowMovementColumnsTest`), unrelated. Green means still exactly those 10.
- No frontend test suite; verification is `npm run build`, warning-clean relative to the known pre-existing warnings.
- ~22 files are dirty in the working tree from unrelated earlier work, including `airkingv5.sql`. Stage only what each task names.
- PowerShell here-strings break `git commit -m`; use `git commit -F -`.
- The camera needs a secure context. Local testing is `http://localhost:3000` (the dev server); phones use `https://<LAN-IP>/` via the Apache 443 vhost. Camera permission is **per-origin including the port**.
