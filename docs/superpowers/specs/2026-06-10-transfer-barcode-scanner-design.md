# Transfer Barcode Scanner + Serial Integrity — Design

**Date:** 2026-06-10
**Status:** Approved (pending spec review)
**Related:** `2026-06-07-transfer-module-redesign-design.md`

## Problem

The interbranch stock transfer (IST) flow lets staff record units leaving the
source (Dispatch) and arriving at the destination (Receive), but both screens
only offer a **per-line text input** for barcodes — keyboard / USB-wedge entry.
The Receive, Adjust, and Issuance modules all have a **camera barcode scanner**
(Start Camera → live preview → "Scan" shutter). Staff want the same scanner on
the transfer screens.

While verifying the backend, three serial-integrity gaps were found that the
scanner work must close:

- **#2 Double-dispatch (partial):** the dispatch scan blocks the *same barcode
  twice within one IST*, but does not verify the serial is actually at the
  source or that it isn't already committed elsewhere.
- **#3 Issue-out while in transit (open):** `IssuanceScanController` looks up a
  serial by `location_id` + `serial_number` + `status_id = 26 (In Stock)` only.
  A dispatched-but-not-yet-received unit still sits at the source location with
  `status_id = 26`, so it can be sold by accident.
- **#5 Re-transfer a gone serial (open):** the dispatch scan blindly sets
  `item_serial.transfer_id` without checking the serial is currently at the
  source. A serial that already moved to another location can be "dispatched"
  again.

(#4 — ownership moving to the destination on receipt — already works via
`TransferService::receive()`.)

The root cause of #3 is two parallel serial-state fields that are not kept in
sync: `serial_type` (`received` / `transferred`) is moved by the transfer flow,
but issuance reads `status_id` (an `inventory`-category status). There is no
"In Transit" serial status in `status_lookup`.

## Goals

1. A camera barcode scanner on **ISTDispatchPage** and **ISTReceivePage** that
   matches the existing scanners (single global camera, auto-routes each scan to
   the correct product line — "Choice 1").
2. Enforce all five integrity requirements:
   - #2 A serial scanned for dispatch cannot be dispatched again.
   - #3 An in-transit (dispatched) serial cannot be issued/sold.
   - #4 After receipt, the serial leaves the source's inventory.
   - #5 A serial that has moved away cannot be re-transferred from the old
     location (whether scanned or manually typed).
3. Do not regress the existing Issuance / Receive / Adjust scanners.

## Non-Goals

- Retrofitting the existing Issuance/Receive/Adjust pages onto the new shared
  camera component (they keep their current copies).
- Introducing a dedicated "In Transit" serial status (explicitly rejected — see
  Decisions).
- Changing the request → approve → IST → dispatch → receive flow itself.

## Locked Decisions

**D1 — Extract a shared camera component.** The ~250-line camera implementation
(device enumeration, html5-qrcode + zxing fallback, beep feedback, camera
picker) is currently copy-pasted in `IssuanceScanPage.jsx` and `BarcodeScan.jsx`.
Instead of pasting a 3rd/4th copy, extract it **once** into a reusable
`useBarcodeCamera` hook + `<CameraScanner>` component and use it in the two
transfer pages. The component must reproduce current behavior exactly. The
existing pages are **not** migrated in this project (no regression risk).

**D2 — Represent "in transit" by reusing `serial_type`.** Dispatch already sets
`serial_type='transferred'`; receive sets it back to `'received'`. The fix is to
make the issuance lookup also require `serial_type='received'`. No migration, no
new seeded status (unseeded statuses have caused runtime 500s in this codebase).

## Architecture

### Shared camera (frontend)

New files under `frontend/src/admin/`:

- `hooks/useBarcodeCamera.js` — owns camera lifecycle: device enumeration &
  physical-vs-virtual sorting, html5-qrcode start/stop, zxing center-crop
  fallback, `BarcodeDetector` path, same-code debounce, beep feedback, camera
  persistence (`localStorage`). Exposes: `{ cameraActive, cameraError,
  cameraDevices, selectedCameraId, startCamera, stopCamera, onDeviceChange,
  manualFrameScan, mountId }` and takes an `onDecode(code)` callback.
- `components/CameraScanner.jsx` — presentational: the camera box, brackets,
  scan line, shutter button, Start/Stop button, and camera `<select>`. Driven by
  the hook. Reuses existing `barcode_scan.css` classes (`bs-*`).

Behavior is lifted verbatim from `IssuanceScanPage.jsx` (the most complete
copy). No behavioral changes.

### Dispatch page (frontend)

`ISTDispatchPage.jsx`:
- Render `<CameraScanner onDecode={handleGlobalScan} />` above the per-line
  cards. The per-line "Scan or type barcode" inputs remain as a manual fallback.
- `handleGlobalScan(code)` calls a new resolve-and-record endpoint
  (`istAPI.scanResolve(id, { barcode })`) — **no `transfer_detail_id`**.
- On success, the response names the resolved line; update that line's
  `scannedTotals` / `sessionScans`, beep success, show inline feedback. On
  rejection, beep error and show the reason (e.g. "not part of this transfer",
  "not in stock at source").

### Receive page (frontend)

`ISTReceivePage.jsx`: same pattern with `istAPI.receiveScanResolve(id, { barcode })`.
The existing per-line manual inputs and consumable Qty box stay.

### Backend — resolve + integrity gates

**Dispatch resolve (`ISTController::scan` / new `scanResolve`, delegating to
`TransferService`).** Accept `barcode` with optional `transfer_detail_id`. When
the id is absent, resolve it; in all cases enforce one validation block:

- *Serialized (appliance):*
  1. Find `ItemSerial` by `serial_number`.
  2. Require its `product_id` to match a line on this IST → gives the line
     (auto-route). Else reject "not part of this transfer".
  3. Require `location_id == from_location_id`. Else reject "not in stock at the
     source location" — enforces **#5**.
  4. Require `serial_type == 'received'` and `status_id == 26 (In Stock)`. Else
     reject "already shipped / not available" — enforces **#2**.
  5. Require `transfer_id` is null or equals this IST. Else reject "committed to
     another transfer" — reinforces **#2**.
  6. On pass: set `transfer_id = this IST`, write the `barcode_scans` row
     (`scan_mode='transfer'`), increment `quantity_scanned`. (`serial_type` is
     flipped to `transferred` at confirm-dispatch, unchanged from today.)
- *Consumable:* resolve barcode → product (reuse the issuance candidate /
  product-code lookup helpers) → matching line; keep existing qty + duplicate
  guards.

**Issuance gate (`IssuanceScanController`, serial Pass 1 ~line 161).** Add
`->where('serial_type', 'received')` to the serial lookup. Enforces **#3**.

**Receive / reverse (`TransferService`).** Already correct for #4. Confirm the
`serial_type` transitions stay consistent (dispatch→`transferred`,
receive→`received` at destination, reverse→`received` at source) so the issuance
gate is correct across the whole lifecycle. No new logic expected; covered by
tests.

## Data flow (dispatch, serialized)

```
Scan serial "SN123"
  → CameraScanner.onDecode("SN123")
  → POST /ist/{id}/scan { barcode: "SN123" }
  → resolve: ItemSerial(SN123) → product 7 → IST line #42
     validate: at from_location? in-stock? serial_type=received? free?
  → record: barcode_scans(scan_mode=transfer), detail.quantity_scanned++
  → 200 { transfer_detail_id: 42, product_name, total_scanned, remaining }
  → UI: tick line #42, beep, "Recorded. 2 remaining."
```

## Testing

Feature tests on the cloned `airkingV5_test` MySQL DB with `DatabaseTransactions`
(SQLite can't run the MySQL-only migrations):

1. **#2** Scan-dispatch a serial, scan it again → 2nd rejected.
2. **#3** Dispatch a serial (In Transit), attempt issuance scan → rejected;
   a normal in-stock serial at that location still issues fine (no regression).
3. **#5** Receive a serial at location A, attempt to dispatch the same serial
   from location B (scanned and manually typed) → both rejected.
4. **#4 / happy path** request → approve → IST → dispatch → receive: serial ends
   at destination with `serial_type='received'`, gone from source inventory.
5. **Auto-route** a consumable barcode and a serial each land on the correct
   line without a client-supplied `transfer_detail_id`.
6. **Reconciliation invariant** `count(active serials) == inventory.quantity_on_hand`
   holds after dispatch, after receive, and after reverse.

Frontend: manual verification that the camera scanner mounts, scans, beeps, and
routes on both pages, and that the manual input fallback still works.

## Risks & Mitigations

- *Shared component drift from the originals.* Mitigation: lift verbatim from
  `IssuanceScanPage`; do not migrate the existing pages in this project.
- *`serial_type` overloaded as availability.* Accepted: it is already the
  transfer module's source of truth; the issuance gate just reads it. A future
  cleanup could introduce a real serial status, out of scope here.
- *Consumable barcode resolution ambiguity.* Reuse the existing, battle-tested
  issuance lookup helpers (`barcodeLookupCandidates`,
  `findProductByBarcodeCandidates`) rather than new logic.

## Rollout

Single branch off the current work. No data migration. The issuance one-line gate
ships with the transfer scanner so the integrity guarantees are consistent on
release.
