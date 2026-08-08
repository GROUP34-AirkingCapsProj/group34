# Incident Camera Capture — Design

**Date:** 2026-08-09
**Branch:** backupV3
**Status:** Approved, ready for planning
**Builds on:** `docs/superpowers/specs/2026-08-03-standalone-incident-reporting-design.md`

## Problem

Evidence photos on incident reports arrive through a plain file picker. A user reporting a theft can attach any image on the device — one taken last week, one of a different item, or one that has nothing to do with the incident. Nothing distinguishes a photo taken at the shelf from a photo pulled out of the gallery, and a reviewer approving a stock write-off has no way to tell them apart.

The request: let users take the picture on the spot, and make it visible that they did.

## Goals

1. Taking a photo with the device camera is the default way to attach evidence, on both incident surfaces.
2. Keeping a file upload as a secondary path, because legitimate cases need it.
3. Recording which path each photo took, and showing that to the reviewer.
4. A preview-and-retake loop, so unusable photos do not become the evidence.

## Non-goals

- **Proving a photo is fresh.** No client-side capture can do this — a determined user can photograph an old photo on another screen. What this delivers is "this came through the app's camera at file-time on this device," which is a deterrent and a signal, not proof. The UI must claim exactly that and no more.
- **EXIF parsing of uploaded files.** Tempting as a second provenance signal, but EXIF is trivially stripped or forged, phones often strip it on share, and it needs a new dependency. It would invite more trust than it earns.
- Reworking the barcode scanner or `useBarcodeCamera`.
- Any change to who may file or resolve incidents.

## Decisions

| Decision | Choice |
|---|---|
| Enforcement | Camera is the default; file upload remains available |
| Surfaces | Both `IncidentFileModal` (standalone) and `picking/IncidentModal` (FIFO override) |
| Provenance store | New nullable `photo_source` column on `incident_reports` |
| Occurred-at | Unchanged — a live capture does **not** set or lock it |
| Capture mechanism | In-app `getUserMedia` + canvas, not the `<input capture>` attribute |

### Why an in-app camera rather than `<input capture>`

`<input type="file" accept="image/*" capture="environment">` would have been a one-attribute change that also works over plain HTTP. It was rejected because **it cannot support the provenance requirement**: platform behaviour varies, several browsers still offer "Camera or Photo Library" despite the attribute, and the app receives an ordinary `File` either way. Stamping `photo_source: 'captured'` on a photo that may have come from the gallery is worse than having no label, because a reviewer would rely on it. It also provides no camera on desktop and no retake loop.

### Why not reuse `useBarcodeCamera`

That hook (413 lines) is welded to `Html5Qrcode`: it mounts the decoder into a div and never exposes a `MediaStream` or `<video>` element, so there is no frame to grab. Only its error-message mapping is reusable.

### Secure-context prerequisite

`getUserMedia` requires `https://` or `localhost`. This is already true of the existing barcode scanner, which emits `INSECURE_ORIGIN: Camera is blocked on plain HTTP unless you use localhost.` (`useBarcodeCamera.js:159-164`).

Verified in this deployment: Apache serves the app at `http://<host>/` on port 80 **and** listens on 443 with a self-signed certificate, where the app is reachable at `https://<host>/Airking_Inventory/frontend/build/`. The camera works wherever the barcode scanner already works, so this adds **no new deployment burden** — but it also means the feature is unavailable over plain HTTP, and the UI must say so rather than appearing broken.

## Component: `CameraCapture.jsx`

New file `frontend/src/admin/components/CameraCapture.jsx`. Its only job is to hand the caller a `File`; nothing about incidents leaks into it.

```
props: { isOpen, onCapture(file), onCancel }
```

**Pipeline:** `getUserMedia({ video: { facingMode: { ideal: 'environment' } } })` → `<video autoPlay muted playsInline>` → shutter draws the current frame to an offscreen `<canvas>` → `canvas.toBlob(...)` → `new File([blob], 'incident.jpg', { type: 'image/jpeg' })`, which the existing `incidentReportsAPI.uploadPhoto()` already accepts.

**States:** `requesting` (permission prompt pending) → `live` (preview + shutter) → `review` (still + Use photo / Retake). Errors resolve to a message plus a **Choose a file instead** escape hatch.

Four implementation details that matter more than they look:

1. **`playsInline` is required.** Without it iOS Safari forces the video into fullscreen playback and the preview is unusable.
2. **Downscale before encoding.** A 12MP frame as PNG exceeds the backend's 5MB limit. Encode JPEG at quality 0.85 with the long edge capped at 1600px — roughly 200–500KB, which is ample for evidence and keeps uploads viable over warehouse wifi.
3. **Stream teardown on every path.** Capture, cancel, modal close, unmount, and error must all run `stream.getTracks().forEach(t => t.stop())`. A leaked stream leaves the device camera light on, which staff reasonably read as the app watching them. This is the single most likely thing to get wrong.
4. **Capture-then-confirm.** The shutter does not submit. Blurry evidence is worthless and a retake loop costs nothing.

**Error messages** reuse the existing helpers in `useBarcodeCamera.js`, which already solve this problem well:

- `cameraAccessErrorMessage(err)` (line ~173) maps `NotAllowedError`, `NotFoundError`, `NotReadableError`, `OverconstrainedError` and the secure-context case to human sentences.
- `assertCameraEnvironment()` (line ~157) performs the `window.isSecureContext` check and returns the `INSECURE_ORIGIN:` message.

**Neither is currently exported** — the file's only exports are `playScanFeedback`, `isLikelyVirtualCameraLabel`, and the hook itself (line 220-222). Add both to the existing export statement. This is a two-word change and is preferable to copying the wording, which would let the barcode scanner's and the camera's messages drift apart for the same underlying failure.

## Provenance

**Migration** `2026_08_09_000001_add_photo_source_to_incident_reports.php`: nullable `photo_source` `varchar(20)` after `photo_path`. Nullable and **not backfilled** — every existing row predates the column and no backfill could honestly guess how those photos arrived. Null reads as "unknown, predates the feature."

**Model:** `PHOTO_SOURCE_CAPTURED = 'captured'` and `PHOTO_SOURCE_UPLOADED = 'uploaded'` constants plus a `PHOTO_SOURCES` array, mirroring how `REASONS` already works.

**It is a client-asserted claim.** The server cannot infer it — both paths arrive as identical multipart uploads. A crafted request could assert `captured` falsely. This is why the reviewer-facing wording is *"Taken in-app"* / *"Uploaded from device"* rather than "verified live photo": the first states what the app observed, the second would state something the system cannot know.

**Validation** in `store()`:

```php
'photo_source' => ['nullable', 'required_with:photo_path', Rule::in(IncidentReport::PHOTO_SOURCES)],
```

`required_with` is the important half: a source without a photo is a claim about nothing. Conversely a `photo_path` with no source stores null.

**Serializer:** `index()` exposes `photo_source` as-is, alongside the existing `photo_url`.

**`airkingv5.sql`** gets the same treatment as the previous migration — hand-edit the `incident_reports` block only. Do **not** regenerate with `mysqldump`: it would sweep up unrelated dev-database drift, and on Windows PowerShell it writes UTF-16 by default, which silently corrupts the file for later import.

## Frontend integration

### Shared hook

`frontend/src/admin/hooks/useIncidentPhoto.js` owns `photoFile`, `photoSource`, `setFromCamera(file)`, `setFromFile(file)`, `clear()`, and `uploadIfAny()` returning `{ photo_path, photo_source }` or nulls.

This exists because both modals already duplicate the upload dance and have drifted (`?? null` in one, `|| null` in the other). Adding a camera and a source field would triple that duplication. The "throw if the upload returned no path" guard added during the previous feature then lives in one place instead of two.

### The field, identical in both modals

```
Evidence photo
[ 📷 Take photo ]  ·  or choose a file
```

**Take photo** is `ak-btn--primary`; the file input becomes a quiet secondary link. That ordering is what makes the camera the default without removing the escape hatch. After either path a thumbnail appears with a **Remove** control and the provenance badge, so the filer sees the same label the reviewer will.

`CameraCapture` renders as a nested surface **inside** the existing `Modal`, not as a second modal — stacked modals in this codebase would fight over focus and backdrop.

### Reviewer side

`IncidentResolveDrawer` shows a badge beside the photo: `ak-badge--green` *"Taken in-app"*, `ak-badge--gray` *"Uploaded from device"*, and nothing at all when `photo_source` is null. It sits alongside the authenticated blob-loaded image.

## Error handling

| Failure | Behaviour |
|---|---|
| Permission denied | Message + file-picker fallback; the form stays usable |
| No camera, or camera in use by another app | Same fallback |
| Insecure origin (plain HTTP) | The specific "needs https:// or localhost" message — this is the one an admin can actually fix |
| `toBlob` returns null | Toast and stay in preview; never attach a null file |
| Still exceeds 5MB after downscale | Re-encode once at lower quality, then refuse with a clear message rather than letting the backend 422 |

## Testing

**Backend** — four cases added to `IncidentPhotoUploadTest`:
- `photo_source` persists and serializes on the index payload
- an invalid `photo_source` value returns 422
- `photo_source` without `photo_path` returns 422
- `photo_path` without `photo_source` stores null

**Frontend** — there is no test suite in this repo, and `getUserMedia` requires a real device, so the camera cannot be meaningfully unit-tested here. It gets a manual checklist instead, run against `npm run demo` over HTTPS:

1. Capture on a phone (rear camera selected by default)
2. Capture on desktop
3. Deny permission → fallback appears, form still usable
4. Cancel mid-preview
5. Retake, then use
6. **Camera light goes off after every exit path** — capture, cancel, close, navigate away
7. A captured incident shows "Taken in-app" in the resolve drawer; an uploaded one shows "Uploaded from device"; a pre-existing incident shows neither

## Related

- Extends the module from `2026-08-03-standalone-incident-reporting-design.md`.
- Touches `IncidentReport`, `IncidentReportController`, `IncidentFileModal`, `picking/IncidentModal`, `IncidentResolveDrawer`, and `airkingv5.sql`.
- Touches `useBarcodeCamera.js` in exactly one way: widening its export statement so the two error helpers can be shared. No behaviour change, and `CameraScanner` is untouched.
- Does **not** touch `IncidentService` or any resolution strategy.

## Environment notes for the implementer

- Schema is a **SQL dump, not a migration history**. `php artisan migrate` with no `--path` fails. Apply by path, to the dev database and to `airkingV5_test` separately.
- Tests use `DatabaseTransactions`, never `RefreshDatabase`.
- The backend suite has **10 known pre-existing failures** (9 `WarrantyClaimTest`, 1 `SerialRowMovementColumnsTest`), all documented in `docs/superpowers/plans/2026-08-03-standalone-incident-reporting.md` and unrelated to incidents. Green means "still exactly those 10."
- No frontend test suite; verification is `npm run build`, warning-clean relative to the pre-existing warnings in `App.jsx`, `Sidebar.jsx`, `BarcodeScan.jsx`, `BarcodeAdjustmentPage.jsx`, `AdminLayout.jsx`.
- ~22 files are dirty in the working tree from unrelated earlier work. Stage only what each task names.
- PowerShell here-strings break `git commit -m`; use `git commit -F -` with a heredoc.
- The manual camera checklist requires HTTPS — `https://<host>/Airking_Inventory/frontend/build/`, accepting the self-signed certificate. Over plain HTTP the camera is expected to show the insecure-origin message, which is itself a checklist item.
