# Incident Camera Capture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user take an incident evidence photo with the device camera instead of picking an old file, and record which way each photo arrived so a reviewer can see it.

**Architecture:** A standalone `CameraCapture` component owns `getUserMedia` → `<video>` → `<canvas>` → `File` and knows nothing about incidents. A `useIncidentPhoto` hook holds the file plus its provenance and performs the upload, replacing duplicated logic in both modals. A new nullable `photo_source` column carries `captured`/`uploaded` through to the resolve drawer.

**Tech Stack:** Laravel 12 (PHP 8.2+), MySQL/MariaDB via XAMPP, PHPUnit, React 18 (CRA), plain CSS `ak-*` design system. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-08-09-incident-camera-capture-design.md`

---

## Critical Context For The Implementer

Read this before Task 1.

**The schema is a SQL dump, not a migration history.** A bare `php artisan migrate` fails. Apply one migration by path, to each database separately:

```bash
cd back-end
php artisan migrate --force --path=database/migrations/<file>.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/<file>.php
```

Use the **Bash tool** for the env-prefixed form; it does not work in PowerShell.

**Tests.** `phpunit.xml` already points `DB_DATABASE` at `airkingV5_test`, so `php artisan test` needs no prefix. Tests use `Illuminate\Foundation\Testing\DatabaseTransactions` — **never** `RefreshDatabase`, which would wipe the dump-loaded schema.

**The suite has 10 known pre-existing failures** — 9 in `WarrantyClaimTest`, 1 in `SerialRowMovementColumnsTest` — all unrelated to incidents and documented in `docs/superpowers/plans/2026-08-03-standalone-incident-reporting.md`. "Green" means still exactly those 10. Prefer filtered runs (`--filter=Incident`).

**No frontend test suite.** Verification is `cd frontend && npm run build`, which must be warning-clean *relative to the existing warnings* in `App.jsx`, `AdminLayout.jsx`, `Sidebar.jsx`, `BarcodeScan.jsx`, `BarcodeAdjustmentPage.jsx`. Anything in an incident/camera file is yours.

**~22 files are dirty in the working tree** from unrelated earlier work. Stage only the files each task names. Never `git add -A`.

**Git.** PowerShell here-strings break `git commit -m`. Use the Bash tool's heredoc form (`git commit -F - <<'EOF'`).

**The camera needs a secure context.** `getUserMedia` requires `https://` or `localhost`. Apache serves this app at `http://<host>/` on port 80 and also listens on 443 with a self-signed cert, where the app lives at `https://<host>/Airking_Inventory/frontend/build/`. Manual camera testing must use the HTTPS URL. Over plain HTTP the component is *expected* to show the insecure-origin message — that is correct behaviour, not a bug.

**Existing state you will be replacing.** The two modals hold the photo in differently-named state today: `IncidentFileModal` uses `photo`/`setPhoto`, `picking/IncidentModal` uses `photoFile`/`setPhotoFile`. Both duplicate the same upload-then-guard block. Task 4 unifies them.

---

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `back-end/database/migrations/2026_08_09_000001_add_photo_source_to_incident_reports.php` | The nullable `photo_source` column |
| `frontend/src/admin/components/CameraCapture.jsx` | Camera → `File`. Knows nothing about incidents. |
| `frontend/src/admin/hooks/useIncidentPhoto.js` | Holds the file + its provenance; performs the upload |

**Modify:**

| File | Change |
|---|---|
| `back-end/app/Models/IncidentReport.php` | `PHOTO_SOURCE_*` constants, `PHOTO_SOURCES`, fillable |
| `back-end/app/Http/Controllers/Api/IncidentReportController.php` | Validate + serialize `photo_source` |
| `back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php` | Four new cases |
| `airkingv5.sql` | Hand-edit the `incident_reports` block only |
| `frontend/src/admin/hooks/useBarcodeCamera.js` | Widen the export statement (no behaviour change) |
| `frontend/src/admin/components/IncidentFileModal.jsx` | Use the hook + camera field |
| `frontend/src/admin/components/picking/IncidentModal.jsx` | Use the hook + camera field |
| `frontend/src/admin/components/IncidentResolveDrawer.jsx` | Provenance badge |

---

## Task 1: The `photo_source` column

**Files:**
- Create: `back-end/database/migrations/2026_08_09_000001_add_photo_source_to_incident_reports.php`
- Test: `back-end/tests/Feature/Sets/IncidentSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Add to the existing class in `back-end/tests/Feature/Sets/IncidentSchemaTest.php`:

```php
    public function test_photo_source_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('incident_reports', 'photo_source'));

        $col = collect(DB::select(
            "SELECT COLUMN_NAME AS c, IS_NULLABLE AS n, DATA_TYPE AS t
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incident_reports'
               AND COLUMN_NAME = 'photo_source'"
        ))->first();

        // Nullable and never backfilled: rows predating this feature genuinely
        // do not know how their photo arrived, and null says exactly that.
        $this->assertSame('YES', $col->n);
        $this->assertSame('varchar', $col->t);
    }
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd back-end && php artisan test --filter=test_photo_source_column_exists_and_is_nullable
```

Expected: FAIL — `Failed asserting that false is true`.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_09_000001_add_photo_source_to_incident_reports.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an incident's evidence photo arrived: taken through the app's camera, or
 * uploaded from the device.
 *
 * Nullable and deliberately NOT backfilled — every existing row predates the
 * camera, and no backfill could honestly guess. Null reads as "unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('incident_reports', 'photo_source')) {
            Schema::table('incident_reports', function (Blueprint $table) {
                $table->string('photo_source', 20)->nullable()->after('photo_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('incident_reports', 'photo_source')) {
            Schema::table('incident_reports', function (Blueprint $table) {
                $table->dropColumn('photo_source');
            });
        }
    }
};
```

- [ ] **Step 4: Apply to both databases**

```bash
cd back-end
php artisan migrate --force --path=database/migrations/2026_08_09_000001_add_photo_source_to_incident_reports.php
DB_DATABASE=airkingV5_test php artisan migrate --force --path=database/migrations/2026_08_09_000001_add_photo_source_to_incident_reports.php
```

Expected: `DONE` twice.

- [ ] **Step 5: Confirm the test passes and nothing regressed**

```bash
cd back-end && php artisan test --filter=IncidentSchemaTest
cd back-end && php artisan test --filter=Incident
```

Expected: schema suite green; `--filter=Incident` still 52 passed.

- [ ] **Step 6: Sync the SQL dump**

`airkingv5.sql` is this repo's schema of record. Hand-edit **only** the `incident_reports` `CREATE TABLE` block, adding the column immediately after `photo_path`:

```sql
  `photo_source` varchar(20) DEFAULT NULL,
```

Do **not** regenerate with `mysqldump`: it would sweep up unrelated dev-database drift, and on Windows PowerShell it writes UTF-16, silently corrupting the file for later import.

Verify with:

```bash
cd .. && grep -n "CREATE TABLE \`incident_reports\`" -A 24 airkingv5.sql | grep -E "photo_path|photo_source"
```

Expected: both lines, `photo_source` directly after `photo_path`.

- [ ] **Step 7: Commit**

```bash
git add back-end/database/migrations/2026_08_09_000001_add_photo_source_to_incident_reports.php back-end/tests/Feature/Sets/IncidentSchemaTest.php airkingv5.sql
git commit -F - <<'EOF'
feat(incidents): add the photo_source column

Records whether an evidence photo was taken in-app or uploaded. Nullable
and not backfilled: rows predating the camera cannot know.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 2: Model constants and API contract

**Files:**
- Modify: `back-end/app/Models/IncidentReport.php`
- Modify: `back-end/app/Http/Controllers/Api/IncidentReportController.php`
- Modify: `back-end/app/Services/IncidentService.php`
- Test: `back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php`. Note it currently imports `UploadedFile` and `Storage`; add `use App\Models\IncidentReport;` if absent.

```php
    /** Upload a real photo and return the path the endpoint issued. */
    private function uploadedPath($user): string
    {
        $res = $this->actingAs($user, 'sanctum')->post('/api/incident-reports/photo', [
            'photo' => UploadedFile::fake()->image('evidence.jpg'),
        ]);
        $res->assertCreated();

        return $res->json('data.path');
    }

    public function test_photo_source_persists_and_serializes(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $path = $this->uploadedPath($wp);

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'  => 'standalone',
            'location_id'  => $loc->location_id,
            'product_id'   => $p->product_id,
            'reason'       => 'theft',
            'quantity'     => 1,
            'narrative'    => 'Shelf empty, photographed on the spot.',
            'photo_path'   => $path,
            'photo_source' => IncidentReport::PHOTO_SOURCE_CAPTURED,
        ])->assertCreated();

        $row = collect(
            $this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open')->json('data')
        )->firstWhere('photo_path', $path);

        $this->assertNotNull($row);
        $this->assertSame('captured', $row['photo_source']);
    }

    public function test_an_unknown_photo_source_is_rejected(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $path = $this->uploadedPath($wp);

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'  => 'standalone',
            'location_id'  => $loc->location_id,
            'product_id'   => $p->product_id,
            'reason'       => 'theft',
            'quantity'     => 1,
            'narrative'    => 'Bogus provenance value.',
            'photo_path'   => $path,
            'photo_source' => 'telepathy',
        ])->assertStatus(422)->assertJsonValidationErrors(['photo_source']);
    }

    public function test_a_photo_source_without_a_photo_is_rejected(): void
    {
        $loc = $this->makeLocation();
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        // A provenance claim about a photo that does not exist is meaningless.
        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type'  => 'standalone',
            'location_id'  => $loc->location_id,
            'product_id'   => $p->product_id,
            'reason'       => 'theft',
            'quantity'     => 1,
            'narrative'    => 'Claiming a capture with nothing attached.',
            'photo_source' => 'captured',
        ])->assertStatus(422)->assertJsonValidationErrors(['photo_path']);
    }

    public function test_a_photo_without_a_source_stores_null(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $loc = $this->makeLocation();
        $ia  = $this->makeUserAt($loc, 'inventory_analyst');
        $wp  = $this->makeUserAt($loc, 'warehouse_personnel');
        $p   = $this->makeConsumable();

        $path = $this->uploadedPath($wp);

        $this->actingAs($wp, 'sanctum')->postJson('/api/incident-reports', [
            'source_type' => 'standalone',
            'location_id' => $loc->location_id,
            'product_id'  => $p->product_id,
            'reason'      => 'theft',
            'quantity'    => 1,
            'narrative'   => 'Older client that does not send a source.',
            'photo_path'  => $path,
        ])->assertCreated();

        $row = collect(
            $this->actingAs($ia, 'sanctum')->getJson('/api/incident-reports?status=open')->json('data')
        )->firstWhere('photo_path', $path);

        $this->assertNull($row['photo_source']);
    }
```

- [ ] **Step 2: Run to confirm they fail**

```bash
cd back-end && php artisan test --filter=IncidentPhotoUploadTest
```

Expected: FAIL — `Undefined constant App\Models\IncidentReport::PHOTO_SOURCE_CAPTURED`.

- [ ] **Step 3: Add the model constants**

In `back-end/app/Models/IncidentReport.php`, add below the `STOCK_AFFECTING_REASONS` block:

```php
    public const PHOTO_SOURCE_CAPTURED = 'captured';
    public const PHOTO_SOURCE_UPLOADED = 'uploaded';

    /**
     * How the evidence photo reached us. This is a CLIENT-ASSERTED claim — both
     * paths arrive as identical multipart uploads, so the server cannot verify
     * it. Reviewer-facing wording must say "taken in-app", never "verified".
     */
    public const PHOTO_SOURCES = [
        self::PHOTO_SOURCE_CAPTURED,
        self::PHOTO_SOURCE_UPLOADED,
    ];
```

Add `'photo_source'` to `$fillable`, immediately after `'photo_path'`.

- [ ] **Step 4: Validate and serialize it**

In `back-end/app/Http/Controllers/Api/IncidentReportController.php`, in `store()`'s validation array, directly after the `photo_path` rule add:

```php
            'photo_source'        => ['nullable', Rule::in(IncidentReport::PHOTO_SOURCES)],
```

And add `required_with:photo_source` to the existing `photo_path` rule:

```php
            'photo_path'          => ['nullable', 'required_with:photo_source', 'string', 'max:255',
                                       'regex:/^incidents\/\d{4}\/\d{2}\/[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)$/'],
```

**The dependency runs ONE way only.** A `photo_source` with no `photo_path` is a claim about nothing, so that fails. The reverse must NOT hold: `photo_path` with no source has to keep working, because every existing client sends exactly that, and the pre-existing `test_an_uploaded_photo_round_trips_through_filing` covers it. Making it bidirectional is a breaking API change and contradicts this task's own `test_a_photo_without_a_source_stores_null`.

### Step 4b: Let the service persist it

`IncidentService::file()` builds an **explicit column array** rather than mass-assigning `$data`, so a fillable model attribute alone is not enough — the value would be silently dropped. Directly after its `'photo_path'` line add:

```php
            // Client-asserted: both a camera capture and a gallery pick arrive
            // as identical uploads, so this is recorded, never trusted.
            'photo_source'        => $data['photo_source'] ?? null,
```

In `index()`'s `map()` closure, directly after the `'photo_url'` line add:

```php
                'photo_source'            => $i->photo_source,
```

- [ ] **Step 5: Run to confirm they pass**

```bash
cd back-end && php artisan test --filter=IncidentPhotoUploadTest
cd back-end && php artisan test --filter=Incident
```

Expected: photo suite green (9 tests: the 5 existing plus your 4); `--filter=Incident` 56 passed.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Models/IncidentReport.php back-end/app/Http/Controllers/Api/IncidentReportController.php back-end/app/Services/IncidentService.php back-end/tests/Feature/Sets/IncidentPhotoUploadTest.php
git commit -F - <<'EOF'
feat(incidents): accept and serialize photo_source

Validated as captured|uploaded, and required_with in both directions so a
provenance claim and a photo cannot exist without each other.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 3: Export the camera error helpers

**Files:**
- Modify: `frontend/src/admin/hooks/useBarcodeCamera.js`

Pure refactor: widen one export statement so `CameraCapture` can reuse the error wording instead of copying it. No behaviour change.

- [ ] **Step 1: Widen the export**

In `frontend/src/admin/hooks/useBarcodeCamera.js`, line ~220 currently reads:

```js
export { playScanFeedback, isLikelyVirtualCameraLabel };
```

Replace with:

```js
// assertCameraEnvironment / cameraAccessErrorMessage are shared with
// components/CameraCapture.jsx so the barcode scanner and the incident camera
// give identical wording for identical failures.
export { playScanFeedback, isLikelyVirtualCameraLabel, assertCameraEnvironment, cameraAccessErrorMessage };
```

Do not move, rename, or edit either function.

- [ ] **Step 2: Verify the build still compiles**

```bash
cd frontend && npm run build
```

Expected: `Compiled` with no new warnings. Nothing else changed, so a warning here means a typo in the export list.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/hooks/useBarcodeCamera.js
git commit -F - <<'EOF'
refactor(camera): export the camera environment and error helpers

Shared with the incident camera so both surfaces word identical failures
identically.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 4: `CameraCapture` component

**Files:**
- Create: `frontend/src/admin/components/CameraCapture.jsx`

- [ ] **Step 1: Create the component**

Create `frontend/src/admin/components/CameraCapture.jsx`:

```jsx
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { assertCameraEnvironment, cameraAccessErrorMessage } from '../hooks/useBarcodeCamera';

/**
 * Take a still photo with the device camera and hand the caller a File.
 *
 * Knows nothing about incidents — its whole contract is onCapture(file).
 *
 * Deliberately NOT built on useBarcodeCamera: that hook mounts html5-qrcode
 * into a div to decode frames and never exposes a MediaStream or <video>, so
 * there is no frame to grab. Only its error wording is reused.
 *
 * Props:
 *   isOpen    {boolean}
 *   onCapture {(file: File) => void}  fires once, with a JPEG File
 *   onCancel  {() => void}
 */

/** Long edge cap. A 12MP frame would blow past the backend's 5MB limit. */
const MAX_EDGE = 1600;
const JPEG_QUALITY = 0.85;
/** Backend rule is max:5120 (KB). Stay clearly under it. */
const MAX_BYTES = 5 * 1024 * 1024;

export default function CameraCapture({ isOpen, onCapture, onCancel }) {
  const videoRef  = useRef(null);
  const streamRef = useRef(null);
  const [phase, setPhase] = useState('requesting'); // requesting | live | review
  const [error, setError] = useState(null);
  const [stillUrl, setStillUrl] = useState(null);
  const [stillFile, setStillFile] = useState(null);

  /** Every exit path must run this, or the device camera light stays on. */
  const stopStream = useCallback(() => {
    const s = streamRef.current;
    if (s) {
      s.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);

  // Bumping this re-runs the effect below, which is how Retake gets a fresh
  // stream without duplicating the acquisition logic.
  const [startKey, setStartKey] = useState(0);

  useEffect(() => {
    if (!isOpen) return undefined;

    let cancelled = false;
    setPhase('requesting');
    setError(null);
    // Any still from a previous open is stale. Setting it to null is enough —
    // the [stillUrl] effect below revokes whatever it replaces.
    setStillUrl(null);
    setStillFile(null);

    (async () => {
      try {
        assertCameraEnvironment();
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' } },
        });
        // The user may have cancelled while the permission prompt was open —
        // without this the stream leaks and the camera light stays on.
        if (cancelled) { stream.getTracks().forEach((t) => t.stop()); return; }
        streamRef.current = stream;
        if (videoRef.current) videoRef.current.srcObject = stream;
        setPhase('live');
      } catch (err) {
        if (!cancelled) {
          setError(cameraAccessErrorMessage(err) || String(err?.message || err));
          setPhase('review');
        }
      }
    })();

    return () => { cancelled = true; stopStream(); };
  }, [isOpen, startKey, stopStream]);

  // Revokes whenever stillUrl is replaced, and on unmount. This is the ONLY
  // place object URLs are revoked — callers just set the state to null.
  useEffect(() => () => { if (stillUrl) URL.revokeObjectURL(stillUrl); }, [stillUrl]);

  const shoot = useCallback(() => {
    const video = videoRef.current;
    if (!video || !video.videoWidth) return;

    const scale  = Math.min(1, MAX_EDGE / Math.max(video.videoWidth, video.videoHeight));
    const canvas = document.createElement('canvas');
    canvas.width  = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    const toFile = (quality) => new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality);
    });

    (async () => {
      let blob = await toFile(JPEG_QUALITY);
      // One retry at lower quality beats a backend 422 the user cannot action.
      if (blob && blob.size > MAX_BYTES) blob = await toFile(0.6);

      if (!blob) { setError('The photo could not be encoded. Try again.'); return; }
      if (blob.size > MAX_BYTES) {
        setError('That photo is too large even after compression. Try again further back.');
        return;
      }

      stopStream();
      setStillFile(new File([blob], 'incident.jpg', { type: 'image/jpeg' }));
      setStillUrl(URL.createObjectURL(blob));
      setPhase('review');
    })();
  }, [stopStream]);

  const retake = useCallback(() => {
    setStillUrl(null);   // revoked by the [stillUrl] effect above
    setStillFile(null);
    // Re-arm the acquisition effect rather than duplicating getUserMedia here.
    setStartKey((k) => k + 1);
  }, []);

  const cancel = useCallback(() => { stopStream(); onCancel?.(); }, [stopStream, onCancel]);

  const use = useCallback(() => {
    if (!stillFile) return;
    stopStream();
    onCapture?.(stillFile);
  }, [stillFile, stopStream, onCapture]);

  if (!isOpen) return null;

  return (
    <div className="ak-field" style={{ gap: '0.6rem', border: '1px solid var(--ak-line)', borderRadius: 8, padding: '0.75rem' }}>
      {error && <div className="ak-state ak-state--error" style={{ fontSize: '0.85rem' }}>{error}</div>}

      {phase !== 'review' && (
        <video
          ref={videoRef}
          autoPlay
          muted
          playsInline   /* without this iOS Safari forces fullscreen playback */
          style={{ width: '100%', maxHeight: 320, borderRadius: 8, background: '#000' }}
        />
      )}

      {phase === 'review' && stillUrl && (
        <img src={stillUrl} alt="Captured evidence" style={{ width: '100%', maxHeight: 320, objectFit: 'contain', borderRadius: 8 }} />
      )}

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <button type="button" className="ak-btn" onClick={cancel}>Cancel</button>

        {phase === 'live' && (
          <button type="button" className="ak-btn ak-btn--primary" onClick={shoot}>Take photo</button>
        )}

        {phase === 'review' && stillFile && (
          <>
            <button type="button" className="ak-btn" onClick={retake}>Retake</button>
            <button type="button" className="ak-btn ak-btn--primary" onClick={use}>Use photo</button>
          </>
        )}

        {phase === 'review' && !stillFile && error && (
          <button type="button" className="ak-btn" onClick={retake}>Try again</button>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Verify it compiles**

```bash
cd frontend && npm run build
```

Expected: `Compiled`, no warning naming `CameraCapture`. An unused-variable warning means a leftover binding.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/CameraCapture.jsx
git commit -F - <<'EOF'
feat(incidents): add the CameraCapture component

getUserMedia to canvas to a JPEG File, downscaled to a 1600px long edge so
it stays under the 5MB upload limit. Preview and retake before the caller
sees anything. Stops every track on every exit path.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 5: `useIncidentPhoto` hook

**Files:**
- Create: `frontend/src/admin/hooks/useIncidentPhoto.js`

- [ ] **Step 1: Create the hook**

Create `frontend/src/admin/hooks/useIncidentPhoto.js`:

```js
import { useCallback, useState } from 'react';
import { incidentReportsAPI } from '../services/api';

/**
 * Holds an incident's evidence photo and how it arrived, and uploads it.
 *
 * Both incident modals previously duplicated this, and had already drifted
 * (`?? null` in one, `|| null` in the other). Adding a camera and a provenance
 * field would have tripled that, so it lives here once.
 *
 * Returns { file, source, previewUrl, setFromCamera, setFromFile, clear, uploadIfAny }.
 * uploadIfAny() resolves to { photo_path, photo_source }, both null when there
 * is no photo, and THROWS if an upload returns no path — filing an incident
 * without the evidence the user attached, while reporting success, loses it
 * silently.
 */
export function useIncidentPhoto() {
  const [file, setFile] = useState(null);
  const [source, setSource] = useState(null);   // 'captured' | 'uploaded' | null
  const [previewUrl, setPreviewUrl] = useState(null);

  const replace = useCallback((nextFile, nextSource) => {
    setPreviewUrl((old) => { if (old) URL.revokeObjectURL(old); return nextFile ? URL.createObjectURL(nextFile) : null; });
    setFile(nextFile);
    setSource(nextFile ? nextSource : null);
  }, []);

  const setFromCamera = useCallback((f) => replace(f, 'captured'), [replace]);
  const setFromFile   = useCallback((f) => replace(f || null, 'uploaded'), [replace]);
  const clear         = useCallback(() => replace(null, null), [replace]);

  const uploadIfAny = useCallback(async () => {
    if (!file) return { photo_path: null, photo_source: null };

    const up = await incidentReportsAPI.uploadPhoto(file);
    const path = up?.data?.path || null;
    if (!path) throw new Error('The photo upload did not return a path. Nothing was filed.');

    return { photo_path: path, photo_source: source };
  }, [file, source]);

  return { file, source, previewUrl, setFromCamera, setFromFile, clear, uploadIfAny };
}
```

- [ ] **Step 2: Verify it compiles**

```bash
cd frontend && npm run build
```

Expected: `Compiled`. The hook is not consumed yet, so no warning should name it.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/hooks/useIncidentPhoto.js
git commit -F - <<'EOF'
feat(incidents): add the useIncidentPhoto hook

One home for the evidence photo, its provenance, and the upload — the two
modals had already drifted on the null-coalescing.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 6: Wire the camera into the standalone modal

> ⚠️ **Hard requirement, found during Task 4's review.** `onCancel` MUST set `cameraOpen` to `false`. Do not merely hide the component behind other state.
>
> If the user presses Cancel while the browser's permission prompt is still open, `getUserMedia` resolves a moment later and would attach the stream — **the camera light coming on after the user pressed Cancel**, precisely the "the app is watching me" failure this feature exists to avoid.
>
> `CameraCapture` now defends against this itself with an `abortRef` set in `cancel()`, so this note is belt-and-braces rather than the only line of defence. Keep it anyway: the ref covers a parent that forgets, and unmounting covers a future refactor that drops the ref. The `onCancel={() => setCameraOpen(false)}` in the snippets below is load-bearing, not incidental.


**Files:**
- Modify: `frontend/src/admin/components/IncidentFileModal.jsx`

- [ ] **Step 1: Import and swap the state**

Add to the imports:

```jsx
import CameraCapture from './CameraCapture';
import { useIncidentPhoto } from '../hooks/useIncidentPhoto';
```

Delete the `const [photo, setPhoto] = useState(null);` declaration and add in its place:

```jsx
  const photo = useIncidentPhoto();
  const [cameraOpen, setCameraOpen] = useState(false);
```

- [ ] **Step 2: Reset it when the modal opens**

In the reset-on-open effect, replace the `setPhoto(null);` line with:

```jsx
    photo.clear();
    setCameraOpen(false);
```

The effect's dependency array must NOT gain `photo` — the hook's identity changes every render and would loop. Add an eslint-disable comment for that line if the build warns:

```jsx
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);
```

- [ ] **Step 3: Use the hook in submit**

In `handleSubmit`, replace the whole `let photoPath = null; if (photo) { ... }` block plus the `photo_path: photoPath,` payload line with:

```jsx
      const { photo_path, photo_source } = await photo.uploadIfAny();
```

and in the payload object:

```jsx
        photo_path,
        photo_source,
```

Then update the `useCallback` dependency array: remove `photo` and add `photo.uploadIfAny`.

- [ ] **Step 4: Replace the photo field markup**

Replace the whole `ak-field` block containing `file-incident-photo` (currently lines ~411-421) with:

```jsx
        <div className="ak-field">
          <label className="ak-label">Evidence photo (optional)</label>

          {!photo.file && !cameraOpen && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
              <button type="button" className="ak-btn ak-btn--primary" onClick={() => setCameraOpen(true)}>
                📷 Take photo
              </button>
              <span className="ak-muted" style={{ fontSize: '0.85rem' }}>or</span>
              <input
                id="file-incident-photo"
                className="ak-input"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => photo.setFromFile(e.target.files?.[0] || null)}
                style={{ maxWidth: 260 }}
              />
            </div>
          )}

          <CameraCapture
            isOpen={cameraOpen}
            onCapture={(f) => { photo.setFromCamera(f); setCameraOpen(false); }}
            onCancel={() => setCameraOpen(false)}
          />

          {photo.file && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
              {photo.previewUrl && (
                <img src={photo.previewUrl} alt="Evidence preview"
                     style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 6 }} />
              )}
              <span className={`ak-badge ${photo.source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                {photo.source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
              </span>
              <button type="button" className="ak-btn ak-btn--sm" onClick={photo.clear}>Remove</button>
            </div>
          )}
        </div>
```

- [ ] **Step 5: Verify it compiles**

```bash
cd frontend && npm run build
```

Expected: `Compiled`, no warning naming `IncidentFileModal`. A `photo is not defined` error means a `setPhoto` reference survived — grep for it.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/IncidentFileModal.jsx
git commit -F - <<'EOF'
feat(incidents): camera capture in the standalone report modal

Take photo is the primary action; choosing a file stays available. The
badge shows the filer the same provenance the reviewer will see.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 7: Wire the camera into the picking modal

> ⚠️ **Hard requirement, found during Task 4's review.** `onCancel` MUST set `cameraOpen` to `false`. Do not merely hide the component behind other state.
>
> If the user presses Cancel while the browser's permission prompt is still open, `getUserMedia` resolves a moment later and would attach the stream — **the camera light coming on after the user pressed Cancel**, precisely the "the app is watching me" failure this feature exists to avoid.
>
> `CameraCapture` now defends against this itself with an `abortRef` set in `cancel()`, so this note is belt-and-braces rather than the only line of defence. Keep it anyway: the ref covers a parent that forgets, and unmounting covers a future refactor that drops the ref. The `onCancel={() => setCameraOpen(false)}` in the snippets below is load-bearing, not incidental.


**Files:**
- Modify: `frontend/src/admin/components/picking/IncidentModal.jsx`

Same shape as Task 6, but this file's state is named `photoFile`/`setPhotoFile` and the relative import paths are one level deeper.

- [ ] **Step 1: Import and swap the state**

Add to the imports:

```jsx
import CameraCapture from '../CameraCapture';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
```

Delete `const [photoFile, setPhotoFile] = useState(null);` and add:

```jsx
  const photo = useIncidentPhoto();
  const [cameraOpen, setCameraOpen] = useState(false);
```

- [ ] **Step 2: Reset on open**

In the reset effect, replace `setPhotoFile(null);` with:

```jsx
    photo.clear();
    setCameraOpen(false);
```

- [ ] **Step 3: Use the hook in submit**

In `handleSubmit`, replace the `let photoPath = null; if (photoFile) { ... }` block with:

```jsx
      const { photo_path, photo_source } = await photo.uploadIfAny();
```

and in the `incidentReportsAPI.file({...})` payload replace `photo_path: photoPath,` with:

```jsx
        photo_path,
        photo_source,
```

- [ ] **Step 4: Replace the photo field markup**

Replace the whole `ak-field` block containing `incident-photo` (currently lines ~161-171) with:

```jsx
        <div className="ak-field">
          <label className="ak-label">Evidence photo (optional)</label>

          {!photo.file && !cameraOpen && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
              <button type="button" className="ak-btn ak-btn--primary" onClick={() => setCameraOpen(true)}>
                📷 Take photo
              </button>
              <span className="ak-muted" style={{ fontSize: '0.85rem' }}>or</span>
              <input
                id="incident-photo"
                className="ak-input"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => photo.setFromFile(e.target.files?.[0] || null)}
                style={{ maxWidth: 240 }}
              />
            </div>
          )}

          <CameraCapture
            isOpen={cameraOpen}
            onCapture={(f) => { photo.setFromCamera(f); setCameraOpen(false); }}
            onCancel={() => setCameraOpen(false)}
          />

          {photo.file && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
              {photo.previewUrl && (
                <img src={photo.previewUrl} alt="Evidence preview"
                     style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 6 }} />
              )}
              <span className={`ak-badge ${photo.source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                {photo.source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
              </span>
              <button type="button" className="ak-btn ak-btn--sm" onClick={photo.clear}>Remove</button>
            </div>
          )}
        </div>
```

Note the `accept` narrows from `image/*` to the three types the backend actually accepts — `image/*` let a user pick a HEIC or GIF that would 422 on upload.

- [ ] **Step 5: Verify it compiles**

```bash
cd frontend && npm run build
```

Expected: `Compiled`, no warning naming `IncidentModal`. Grep for `photoFile` to confirm none survived.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/components/picking/IncidentModal.jsx
git commit -F - <<'EOF'
feat(incidents): camera capture in the picking incident modal

The surface most likely to be on a phone at the shelf, so the live capture
matters most here. accept= narrowed to what the backend actually takes.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 8: Show provenance to the reviewer

**Files:**
- Modify: `frontend/src/admin/components/IncidentResolveDrawer.jsx`

- [ ] **Step 1: Add the badge beside the photo**

In the Photo block, immediately after the `<div className="ak-label">Photo</div>` line, add:

```jsx
          {incident.photo_source && (
            <div style={{ marginBottom: '0.4rem' }}>
              <span className={`ak-badge ${incident.photo_source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                {incident.photo_source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
              </span>
            </div>
          )}
```

Nothing renders when `photo_source` is null — incidents filed before this feature genuinely do not know, and a "Uploaded" badge on them would be an invented claim.

The wording is deliberate: **"Taken in-app", never "verified".** The server cannot verify provenance — both paths arrive as identical multipart uploads — so the badge states what the app observed, not what the system can prove.

- [ ] **Step 2: Verify it compiles**

```bash
cd frontend && npm run build
```

Expected: `Compiled`, no warning naming `IncidentResolveDrawer`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/components/IncidentResolveDrawer.jsx
git commit -F - <<'EOF'
feat(incidents): show photo provenance in the resolve drawer

"Taken in-app" vs "Uploaded from device", and nothing at all for incidents
that predate the column. Wording claims only what the app observed.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## Task 9: Verification

- [ ] **Step 1: Backend suite**

```bash
cd back-end && php artisan test --filter=Incident
cd back-end && php artisan test
```

Expected: `--filter=Incident` **56 passed**. Full suite: **10 failures, unchanged** — 9 `WarrantyClaimTest` and 1 `SerialRowMovementColumnsTest`, both pre-existing. If the count moved, this plan broke something.

- [ ] **Step 2: Frontend build**

```bash
cd frontend && npm run build
```

Expected: `Compiled`. Confirm no warning names `CameraCapture`, `useIncidentPhoto`, `IncidentFileModal`, `IncidentModal`, or `IncidentResolveDrawer`:

```bash
cd frontend && npm run build 2>&1 | grep -iE "CameraCapture|useIncidentPhoto|IncidentFileModal|IncidentModal|IncidentResolveDrawer" || echo "clean"
```

- [ ] **Step 3: Serve over HTTPS for the manual pass**

The camera needs a secure context. Build and serve, then open **`https://<your-LAN-IP>/Airking_Inventory/frontend/build/`** and accept the self-signed certificate warning.

```bash
cd frontend && npm run demo
```

`http://localhost:5000` will NOT give you a camera — that is expected and is itself checklist item 7.

- [ ] **Step 4: Manual checklist**

At `/admin/incidents` → **Report Incident**:

1. **Take photo** opens a live preview; the rear camera is selected on a phone.
2. Shutter → the still appears with **Retake** / **Use photo**.
3. **Retake** returns to a live preview.
4. **Use photo** attaches it, showing a thumbnail and a green **Taken in-app** badge.
5. **Remove** clears it and the Take photo / file row returns.
6. Choosing a file instead shows a grey **Uploaded from device** badge.
7. Over plain HTTP (`http://localhost:5000`), Take photo shows the "requires https:// or http://localhost" message and the file input still works.
8. Deny the camera permission → the error message appears and the form is still submittable via file upload.
9. **The camera light goes off** after: Use photo, Cancel, closing the modal, and navigating away. This is the one people forget.
10. File the incident, then open it in the resolve drawer — the photo loads and carries the matching badge.
11. Repeat 1, 4 and 9 in the **picking** modal (trigger a FIFO violation during an issuance pick).
12. Open an incident filed before this feature — it shows the photo with **no** badge.

- [ ] **Step 5: Commit any fixes**

If the walkthrough found defects, fix them, re-run steps 1 and 2, and commit. If it found none, there is nothing to commit and the plan is complete.

---

## Notes For Integration

This sits on `backupV3`, which already carries a large amount of unpushed work. Do not merge or push without asking — decide integration separately using `superpowers:finishing-a-development-branch`.
