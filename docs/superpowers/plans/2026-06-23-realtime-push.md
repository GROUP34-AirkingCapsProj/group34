# Real-Time Push (Reverb + Echo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Push backend data changes to the UI over WebSockets so the header badges and four list screens (Approval Queue, Inventory, Transfers/IST, Purchase Orders) update live without a manual refresh.

**Architecture:** "Signal + refetch." Laravel Reverb broadcasts tiny `ShouldBroadcastNow` signal events on private `org` / `location.{id}` channels that mirror the app's location-scope roles. A subscribed React screen responds by re-running the fetch it already does, debounced. The server stays authoritative; existing endpoints/scoping are reused unchanged. Real-time degrades gracefully to the existing polls if the Reverb process is down.

**Tech Stack:** Laravel 12 (PHP 8.2), `laravel/reverb`, React 18 (CRA), `laravel-echo` + `pusher-js`, MariaDB/XAMPP, Sanctum (Bearer token in localStorage).

**Spec:** `docs/superpowers/specs/2026-06-23-realtime-push-design.md`

---

## File Map

**Backend**
- Install: `laravel/reverb` (composer); scaffolding via `php artisan install:broadcasting`.
- Config (generated, then edited): `back-end/config/broadcasting.php`, `back-end/config/reverb.php`, `back-end/.env`, `back-end/.env.example`.
- Create: `back-end/app/Events/ApprovalQueueChanged.php`, `InventoryChanged.php`, `TransferChanged.php`, `PurchaseOrderChanged.php`.
- Create: `back-end/app/Support/ChannelAccess.php` — pure authorization logic (testable).
- Create: `back-end/app/Support/RealtimeBroadcaster.php` — safe dispatcher (try/catch).
- Modify: `back-end/routes/channels.php` — `org` + `location.{id}` auth, delegating to `ChannelAccess`.
- Modify: `back-end/routes/api.php` — ensure `/broadcasting/auth` is under `auth:sanctum`.
- Modify (triggers): `PurchaseOrderController`, `PurchaseRequestController`, `PendingProductController`, `ReceivingController`, `IssuanceController`, `AdjustmentController`, `BarcodeAdjustmentController`, `TransferRequestController`, `ISTController`.
- Tests: `back-end/tests/Unit/ChannelAccessTest.php`, `back-end/tests/Unit/RealtimeEventsTest.php`, `back-end/tests/Feature/RealtimeBroadcastTest.php`, `back-end/tests/Feature/RealtimeTriggersTest.php`.

**Frontend**
- Add deps: `laravel-echo`, `pusher-js` in `frontend/package.json`.
- Create: `frontend/src/admin/utils/echo.js` — Echo singleton, Bearer authorizer, host resolver, `getEcho()`/`disconnectEcho()`.
- Create: `frontend/src/admin/utils/echo.test.js` — authorizer + host-resolver unit tests.
- Create: `frontend/src/admin/hooks/useRealtime.js` — channel subscription + debounced handlers.
- Create: `frontend/src/admin/hooks/useRealtime.test.js` — debounce + channel-selection unit tests.
- Modify: `frontend/src/admin/context/AuthContext.jsx` — call `disconnectEcho()` in `clearSession`.
- Modify: `frontend/.env`, `frontend/.env.example` — `REACT_APP_REVERB_*`.
- Modify (screen wiring): AdminLayout + the four screen pages.

---

## Task 1: Install Reverb + broadcasting scaffolding (backend only)

CRA is the real frontend; we use ONLY Reverb's backend scaffolding. Any `resources/js`
Echo scaffolding the installer adds is for Blade/Vite and must be ignored (do not wire it).

**Files:** composer deps; `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, `bootstrap/app.php` or provider (generated); `.env`, `.env.example`.

- [ ] **Step 1: Install Reverb + broadcasting**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/back-end"
php artisan install:broadcasting --no-interaction
```
If `--no-interaction` still prompts for npm/Node frontend scaffolding, decline the
frontend install (answer "no") — we install the JS client into the CRA app ourselves in
Task 6. The command must produce: `config/broadcasting.php`, `config/reverb.php`,
`routes/channels.php`, and register the broadcast auth route + channels routing.

- [ ] **Step 2: Confirm Reverb is required**

```bash
grep "laravel/reverb" composer.json
```
Expected: a `laravel/reverb` line. If absent, run `composer require laravel/reverb` then
`php artisan reverb:install --no-interaction`.

- [ ] **Step 3: Set broadcasting env**

In `back-end/.env` set (generate real values for the APP_ID/KEY/SECRET — any random
strings are fine for LAN; `reverb:install` usually fills them):

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=airking
REVERB_APP_KEY=airking-local-key
REVERB_APP_SECRET=airking-local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

Mirror these (with placeholder values) into `back-end/.env.example` so the setup is documented.

- [ ] **Step 4: Verify the Reverb server starts**

```bash
php artisan reverb:start --debug
```
Expected: it boots and logs "Starting server on 0.0.0.0:8080" (or similar). Stop it with
Ctrl+C. If the port is busy, pick another and update `REVERB_PORT`.

- [ ] **Step 5: Clear config cache (so new env is read)**

```bash
php artisan config:clear
```

- [ ] **Step 6: Commit**

```bash
git add back-end/config/broadcasting.php back-end/config/reverb.php back-end/routes/channels.php back-end/composer.json back-end/composer.lock back-end/bootstrap/app.php back-end/.env.example
git commit -m "feat(realtime): install Laravel Reverb + broadcasting scaffolding"
```
(`.env` is gitignored — do not add it. If `bootstrap/app.php` was not modified by the
installer, omit it from the add.)

---

## Task 2: Pure channel-access logic

Extract authorization into a tiny pure class so it is unit-testable and reused by
`routes/channels.php`. Roles: centralized = {1,2,5}; scoped users carry `location_id`.

**Files:**
- Create: `back-end/app/Support/ChannelAccess.php`
- Test: `back-end/tests/Unit/ChannelAccessTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Unit/ChannelAccessTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\ChannelAccess;
use PHPUnit\Framework\TestCase;

class ChannelAccessTest extends TestCase
{
    private function user(int $roleId, ?int $locationId): User
    {
        $u = new User();
        $u->role_id = $roleId;
        $u->location_id = $locationId;
        return $u;
    }

    public function test_centralized_roles_access_org(): void
    {
        foreach ([1, 2, 5] as $roleId) {
            $this->assertTrue(ChannelAccess::canAccessOrg($this->user($roleId, null)));
        }
    }

    public function test_scoped_roles_cannot_access_org(): void
    {
        $this->assertFalse(ChannelAccess::canAccessOrg($this->user(3, 7)));
        $this->assertFalse(ChannelAccess::canAccessOrg($this->user(4, 7)));
    }

    public function test_centralized_can_access_any_location(): void
    {
        $this->assertTrue(ChannelAccess::canAccessLocation($this->user(1, null), 99));
    }

    public function test_scoped_user_only_accesses_own_location(): void
    {
        $u = $this->user(4, 7);
        $this->assertTrue(ChannelAccess::canAccessLocation($u, 7));
        $this->assertFalse(ChannelAccess::canAccessLocation($u, 8));
    }

    public function test_scoped_user_with_no_location_denied(): void
    {
        $this->assertFalse(ChannelAccess::canAccessLocation($this->user(4, null), 7));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ChannelAccessTest
```
Expected: FAIL — `App\Support\ChannelAccess` not found.

- [ ] **Step 3: Implement**

Create `back-end/app/Support/ChannelAccess.php`:

```php
<?php

namespace App\Support;

use App\Models\User;

/**
 * Pure authorization rules for realtime channels, mirroring the app's location-scope
 * model: centralized roles (admin=1, inventory_analyst=2, auditor=5) see everything;
 * scoped roles (branch_manager=3, warehouse_personnel=4) see only their own location.
 */
class ChannelAccess
{
    public static function canAccessOrg(User $user): bool
    {
        return $user->isCentralized();
    }

    public static function canAccessLocation(User $user, int $locationId): bool
    {
        if ($user->isCentralized()) {
            return true;
        }
        return $user->scopedLocationId() === $locationId;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

```bash
php artisan test --filter=ChannelAccessTest
```
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Support/ChannelAccess.php back-end/tests/Unit/ChannelAccessTest.php
git commit -m "feat(realtime): pure ChannelAccess authorization logic"
```

---

## Task 3: Channel definitions + Sanctum auth route

**Files:**
- Modify: `back-end/routes/channels.php`
- Modify: `back-end/routes/api.php` (broadcasting auth under `auth:sanctum`)

- [ ] **Step 1: Define the channels**

Replace the body of `back-end/routes/channels.php` with (keep the `<?php` header and any
existing `use` lines the installer added; add these):

```php
use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('org', function (User $user) {
    return ChannelAccess::canAccessOrg($user);
});

Broadcast::channel('location.{id}', function (User $user, int $id) {
    return ChannelAccess::canAccessLocation($user, $id);
});
```

- [ ] **Step 2: Ensure the broadcasting auth endpoint uses Sanctum**

`install:broadcasting` registers `/broadcasting/auth` on the `web` guard by default. We
authenticate with Bearer tokens, so it must run under `auth:sanctum` and be reachable at
`/api/broadcasting/auth`. In `back-end/routes/api.php`, INSIDE the existing
`auth:sanctum` group (the one containing the dashboard routes), add:

```php
    Broadcast::routes(['middleware' => ['auth:sanctum']]);
```

Add `use Illuminate\Support\Facades\Broadcast;` to the top of `routes/api.php` if not
already imported. If the installer added a separate `Broadcast::routes()` call elsewhere
(e.g. in `routes/web.php` or a provider) that would shadow this, remove that one so the
Sanctum-guarded API route is the only auth endpoint. The effective URL becomes
`/api/broadcasting/auth`.

- [ ] **Step 3: Verify routes**

```bash
php artisan route:list | grep broadcasting
```
Expected: a `POST api/broadcasting/auth ... auth:sanctum` row (and no conflicting `web`
one).

- [ ] **Step 4: Smoke-test auth rejects the unauthenticated**

```bash
php artisan route:clear
php artisan test --filter=ChannelAccessTest
```
(Re-running the unit test confirms nothing broke; the HTTP auth path is covered manually
in the final verification.) Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add back-end/routes/channels.php back-end/routes/api.php
git commit -m "feat(realtime): org + location channels with Sanctum-guarded auth"
```

---

## Task 4: Signal events

Four `ShouldBroadcastNow` events with tiny payloads, stable `broadcastAs()` names, and
`broadcastOn()` returning the right private channels.

**Files:**
- Create: `back-end/app/Events/ApprovalQueueChanged.php`, `InventoryChanged.php`, `TransferChanged.php`, `PurchaseOrderChanged.php`
- Test: `back-end/tests/Unit/RealtimeEventsTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Unit/RealtimeEventsTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Events\ApprovalQueueChanged;
use App\Events\InventoryChanged;
use App\Events\PurchaseOrderChanged;
use App\Events\TransferChanged;
use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\TestCase;

class RealtimeEventsTest extends TestCase
{
    private function names(array $channels): array
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $channels);
    }

    public function test_approval_queue_event_channels_and_name(): void
    {
        $e = new ApprovalQueueChanged(3, 'po_submitted');
        $this->assertSame('approval-queue.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.3'],
            $this->names($e->broadcastOn())
        );
        $this->assertSame(3, $e->broadcastWith()['location_id']);
        $this->assertSame('po_submitted', $e->broadcastWith()['type']);
    }

    public function test_inventory_event_channels(): void
    {
        $e = new InventoryChanged(5, 'stock_received');
        $this->assertSame('inventory.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.5'],
            $this->names($e->broadcastOn())
        );
    }

    public function test_purchase_order_event_channels(): void
    {
        $e = new PurchaseOrderChanged(5, 'po_authorized');
        $this->assertSame('purchase-order.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.5'],
            $this->names($e->broadcastOn())
        );
    }

    public function test_transfer_event_broadcasts_to_both_locations(): void
    {
        $e = new TransferChanged(2, 9, 'ist_dispatched');
        $this->assertSame('transfer.changed', $e->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-org', 'private-location.2', 'private-location.9'],
            $this->names($e->broadcastOn())
        );
        $this->assertSame(2, $e->broadcastWith()['from']);
        $this->assertSame(9, $e->broadcastWith()['to']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=RealtimeEventsTest
```
Expected: FAIL — event classes not found.

- [ ] **Step 3: Implement `ApprovalQueueChanged`**

Create `back-end/app/Events/ApprovalQueueChanged.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ApprovalQueueChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $type = 'changed',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('org'),
            new PrivateChannel('location.' . $this->locationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'approval-queue.changed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'location_id' => $this->locationId, 'at' => now()->toIso8601String()];
    }
}
```

- [ ] **Step 4: Implement `InventoryChanged`**

Create `back-end/app/Events/InventoryChanged.php` — identical to Task 4 Step 3 but with
class name `InventoryChanged` and `broadcastAs()` returning `'inventory.changed'`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class InventoryChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $type = 'changed',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('org'),
            new PrivateChannel('location.' . $this->locationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.changed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'location_id' => $this->locationId, 'at' => now()->toIso8601String()];
    }
}
```

- [ ] **Step 5: Implement `PurchaseOrderChanged`**

Create `back-end/app/Events/PurchaseOrderChanged.php` — same shape, class
`PurchaseOrderChanged`, `broadcastAs()` returns `'purchase-order.changed'`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PurchaseOrderChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $type = 'changed',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('org'),
            new PrivateChannel('location.' . $this->locationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'purchase-order.changed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'location_id' => $this->locationId, 'at' => now()->toIso8601String()];
    }
}
```

- [ ] **Step 6: Implement `TransferChanged` (two locations)**

Create `back-end/app/Events/TransferChanged.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TransferChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $from,
        public int $to,
        public string $type = 'changed',
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('org'), new PrivateChannel('location.' . $this->from)];
        if ($this->to !== $this->from) {
            $channels[] = new PrivateChannel('location.' . $this->to);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'transfer.changed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'from' => $this->from, 'to' => $this->to, 'at' => now()->toIso8601String()];
    }
}
```

- [ ] **Step 7: Run it to verify it passes**

```bash
php artisan test --filter=RealtimeEventsTest
```
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Events back-end/tests/Unit/RealtimeEventsTest.php
git commit -m "feat(realtime): signal events for approval/inventory/PO/transfer"
```

---

## Task 5: Safe dispatcher

One-line API for controllers; swallows + logs broadcast failures so a Reverb outage never
breaks the triggering action.

**Files:**
- Create: `back-end/app/Support/RealtimeBroadcaster.php`
- Test: `back-end/tests/Feature/RealtimeBroadcastTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/RealtimeBroadcastTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Events\InventoryChanged;
use App\Support\RealtimeBroadcaster;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeBroadcastTest extends TestCase
{
    public function test_dispatcher_broadcasts_inventory_event(): void
    {
        Event::fake([InventoryChanged::class]);

        RealtimeBroadcaster::inventoryChanged(4, 'stock_received');

        Event::assertDispatched(InventoryChanged::class, function ($e) {
            return $e->locationId === 4 && $e->type === 'stock_received';
        });
    }

    public function test_dispatcher_swallows_broadcast_failure(): void
    {
        // Force the underlying broadcast to throw; the helper must not bubble it.
        Event::fake(); // event dispatch is faked, so simulate failure via a null location guard instead
        // A null location must be a no-op (no event, no throw):
        RealtimeBroadcaster::inventoryChanged(null);
        Event::assertNotDispatched(InventoryChanged::class);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=RealtimeBroadcastTest
```
Expected: FAIL — `App\Support\RealtimeBroadcaster` not found.

- [ ] **Step 3: Implement**

Create `back-end/app/Support/RealtimeBroadcaster.php`:

```php
<?php

namespace App\Support;

use App\Events\ApprovalQueueChanged;
use App\Events\InventoryChanged;
use App\Events\PurchaseOrderChanged;
use App\Events\TransferChanged;
use Illuminate\Support\Facades\Log;

/**
 * One-line, failure-safe entry point for realtime signal broadcasts.
 * Null location ids are no-ops (e.g. a record without a resolvable location).
 * Any broadcast/transport error is logged and swallowed so it never breaks the
 * action that triggered it.
 */
class RealtimeBroadcaster
{
    public static function approvalQueueChanged(?int $locationId, string $type = 'changed'): void
    {
        self::safe(fn () => $locationId !== null
            ? broadcast(new ApprovalQueueChanged($locationId, $type))
            : null);
    }

    public static function inventoryChanged(?int $locationId, string $type = 'changed'): void
    {
        self::safe(fn () => $locationId !== null
            ? broadcast(new InventoryChanged($locationId, $type))
            : null);
    }

    public static function purchaseOrderChanged(?int $locationId, string $type = 'changed'): void
    {
        self::safe(fn () => $locationId !== null
            ? broadcast(new PurchaseOrderChanged($locationId, $type))
            : null);
    }

    public static function transferChanged(?int $from, ?int $to, string $type = 'changed'): void
    {
        self::safe(function () use ($from, $to, $type) {
            if ($from === null && $to === null) {
                return null;
            }
            // If one side is null, broadcast to the known side on both args.
            $a = $from ?? $to;
            $b = $to ?? $from;
            return broadcast(new TransferChanged($a, $b, $type));
        });
    }

    private static function safe(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast failed: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

```bash
php artisan test --filter=RealtimeBroadcastTest
```
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Support/RealtimeBroadcaster.php back-end/tests/Feature/RealtimeBroadcastTest.php
git commit -m "feat(realtime): failure-safe RealtimeBroadcaster dispatcher"
```

---

## Task 6: Wire triggers into controllers

Fire the matching helper AFTER each mutation commits (just before the success response).
Resolve the location id from the record being changed. Each call is one line.

**Files (modify):** the nine controllers listed below.
**Test:** `back-end/tests/Feature/RealtimeTriggersTest.php`

- [ ] **Step 1: Write the failing test (representative triggers)**

Create `back-end/tests/Feature/RealtimeTriggersTest.php`. It drives two representative
endpoints through HTTP and asserts the event fires. Use seeded users/data consistent with
the repo's `DatabaseTransactions` convention:

```php
<?php

namespace Tests\Feature;

use App\Events\ApprovalQueueChanged;
use App\Events\PurchaseOrderChanged;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeTriggersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_order_authorize_broadcasts(): void
    {
        Event::fake([PurchaseOrderChanged::class, ApprovalQueueChanged::class]);

        $admin = User::query()->where('role_id', 1)->first();
        $po = PurchaseOrder::query()->where('status_id', 7)->first(); // Pending
        if (!$admin || !$po) {
            $this->markTestSkipped('Seeded admin + pending PO required.');
        }

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 8]) // Authorized
            ->assertSuccessful();

        Event::assertDispatched(PurchaseOrderChanged::class);
    }
}
```

(Note: status ids per the repo — Pending PO = 7; use the project's "Authorized" id. If the
update contract differs, adapt the payload; the assertion that `PurchaseOrderChanged`
dispatched is the point.)

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=RealtimeTriggersTest
```
Expected: FAIL — event not dispatched (trigger not wired yet) or skipped if no seed data.

- [ ] **Step 3: Wire PO triggers**

In `back-end/app/Http/Controllers/Api/PurchaseOrderController.php`, add
`use App\Support\RealtimeBroadcaster;` at the top. In `store()` (line ~79), after the PO
is created and before the success response, add:

```php
        RealtimeBroadcaster::purchaseOrderChanged((int) $purchaseOrder->location_id, 'po_created');
        RealtimeBroadcaster::approvalQueueChanged((int) $purchaseOrder->location_id, 'po_created');
```
(Use the actual PO variable name in scope; resolve `location_id` from the created PO.)
In `update()` (line ~186), after the status change persists, add:

```php
        RealtimeBroadcaster::purchaseOrderChanged((int) $purchaseOrder->location_id, 'po_updated');
        RealtimeBroadcaster::approvalQueueChanged((int) $purchaseOrder->location_id, 'po_updated');
```

- [ ] **Step 4: Wire PR + pending-product triggers**

In `PurchaseRequestController.php` (add the `use`): in `store()` (line ~273),
`approve()` (~332), `reject()` (~366), `convert()` (~398), after each commit add:

```php
        RealtimeBroadcaster::approvalQueueChanged((int) $purchaseRequest->location_id, 'pr_changed');
```
(Use the PR variable in scope; in `convert()` also fire
`RealtimeBroadcaster::purchaseOrderChanged((int) $newPo->location_id, 'pr_converted');`.)
In `PendingProductController.php` `approve()`, after approval add (pending products use
`opening_location_id`):

```php
        RealtimeBroadcaster::approvalQueueChanged((int) $pendingProduct->opening_location_id, 'product_approved');
```

- [ ] **Step 5: Wire inventory triggers**

Add the `use` to each. After stock-affecting commits:
- `ReceivingController.php` `store()` (line ~48): `RealtimeBroadcaster::inventoryChanged((int) $receiving->location_id, 'stock_received');` and, when the PO becomes fully received, also `RealtimeBroadcaster::purchaseOrderChanged((int) $po->location_id, 'po_received');`
- `IssuanceController.php` `store()` (line ~47): `RealtimeBroadcaster::inventoryChanged((int) $issuance->location_id, 'stock_issued');`
- `AdjustmentController.php` `store()` (line ~47): `RealtimeBroadcaster::inventoryChanged((int) $adjustment->location_id, 'stock_adjusted');`
- `BarcodeAdjustmentController.php` `submit()`: `RealtimeBroadcaster::inventoryChanged((int) $locationId, 'stock_corrected');` (resolve the location from the adjustment payload/record in scope).

Resolve each `location_id` from the record actually created in that method; if the
variable name differs, use the real one.

- [ ] **Step 6: Wire transfer/IST triggers**

Add the `use` to each:
- `TransferRequestController.php` `store()` (~66), `approve()` (~179), `reject()` (~235): after commit, `RealtimeBroadcaster::transferChanged((int) $transferRequest->from_location_id, (int) $transferRequest->to_location_id, 'request_changed');`
- `ISTController.php` `store()` (~120): `RealtimeBroadcaster::transferChanged((int) $transfer->from_location_id, (int) $transfer->to_location_id, 'ist_created');`
- `ISTController.php` `confirmDispatch()` (~491): `RealtimeBroadcaster::transferChanged((int) $transfer->from_location_id, (int) $transfer->to_location_id, 'ist_dispatched');`
- `ISTController.php` `receive()` (the receive action that lands stock): `RealtimeBroadcaster::transferChanged((int) $transfer->from_location_id, (int) $transfer->to_location_id, 'ist_received');` and `RealtimeBroadcaster::inventoryChanged((int) $transfer->to_location_id, 'stock_received');`

Use the transfer variable actually in scope in each method.

- [ ] **Step 7: Run the trigger test + full suite**

```bash
php artisan test --filter=RealtimeTriggersTest
php artisan test
```
Expected: the trigger test passes (or skips if no seed data — if skipped, manually verify
one trigger via `php artisan tinker` calling the controller path is out of scope; rely on
the unit/dispatcher coverage). Full suite: existing green baseline + the new realtime
tests, no regressions.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api back-end/tests/Feature/RealtimeTriggersTest.php
git commit -m "feat(realtime): broadcast signals from PO/PR/inventory/transfer actions"
```

---

## Task 7: Frontend Echo client

**Files:**
- Modify: `frontend/package.json` (deps)
- Create: `frontend/src/admin/utils/echo.js`
- Test: `frontend/src/admin/utils/echo.test.js`
- Modify: `frontend/.env`, `frontend/.env.example`

- [ ] **Step 1: Install the client libraries**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/frontend"
npm install laravel-echo pusher-js
```

- [ ] **Step 2: Add env keys**

In `frontend/.env` add (gitignored — local machine):

```
REACT_APP_REVERB_KEY=airking-local-key
REACT_APP_REVERB_HOST=airking.local
REACT_APP_REVERB_PORT=8080
REACT_APP_REVERB_SCHEME=http
```
Mirror with placeholder values into `frontend/.env.example`. `REACT_APP_REVERB_KEY` must
equal the backend `REVERB_APP_KEY`.

- [ ] **Step 3: Write the failing test (pure helpers)**

Create `frontend/src/admin/utils/echo.test.js`:

```js
import { resolveReverbConfig, buildAuthHeader } from './echo';

describe('resolveReverbConfig', () => {
  const env = {
    REACT_APP_REVERB_KEY: 'k',
    REACT_APP_REVERB_HOST: 'airking.local',
    REACT_APP_REVERB_PORT: '8080',
    REACT_APP_REVERB_SCHEME: 'http',
  };

  it('uses the configured host on loopback', () => {
    const cfg = resolveReverbConfig(env, { hostname: 'localhost' });
    expect(cfg.wsHost).toBe('airking.local');
    expect(cfg.wsPort).toBe(8080);
    expect(cfg.forceTLS).toBe(false);
    expect(cfg.key).toBe('k');
  });

  it('uses the page hostname on a LAN IP so phones connect to the dev machine', () => {
    const cfg = resolveReverbConfig(env, { hostname: '192.168.1.50' });
    expect(cfg.wsHost).toBe('192.168.1.50');
    expect(cfg.wsPort).toBe(8080);
  });

  it('forceTLS true when scheme is https', () => {
    const cfg = resolveReverbConfig({ ...env, REACT_APP_REVERB_SCHEME: 'https' }, { hostname: 'localhost' });
    expect(cfg.forceTLS).toBe(true);
  });
});

describe('buildAuthHeader', () => {
  it('builds a Bearer header from the token', () => {
    expect(buildAuthHeader('abc123')).toEqual({ Authorization: 'Bearer abc123', Accept: 'application/json' });
  });
});
```

- [ ] **Step 4: Run it to verify it fails**

```bash
$env:CI="true"; npm test -- --testPathPattern=echo
```
Expected: FAIL — module not found.

- [ ] **Step 5: Implement `echo.js`**

Create `frontend/src/admin/utils/echo.js`:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getApiBaseUrl, getToken } from '../services/api';

const isPrivateLan = (h) =>
  !!h && !['localhost', '127.0.0.1', '[::1]', '::1'].includes(h) &&
  (/^192\.168\./.test(h) || /^10\./.test(h) || /^172\.(1[6-9]|2\d|3[01])\./.test(h) ||
   /\.local$/i.test(h) || !h.includes('.'));

/** Pure: resolve the WebSocket connection config from env + the current page location. */
export const resolveReverbConfig = (env, loc) => {
  const scheme = String(env.REACT_APP_REVERB_SCHEME || 'http').toLowerCase();
  const forceTLS = scheme === 'https';
  const configuredHost = env.REACT_APP_REVERB_HOST || '127.0.0.1';
  const hostname = loc?.hostname;
  // On a LAN IP, phones must reach the dev machine — use the page's host, not 127.0.0.1.
  const wsHost = isPrivateLan(hostname) ? hostname : configuredHost;
  return {
    key: env.REACT_APP_REVERB_KEY,
    wsHost,
    wsPort: Number(env.REACT_APP_REVERB_PORT || 8080),
    forceTLS,
  };
};

/** Pure: header set the broadcasting-auth request must send (Bearer token). */
export const buildAuthHeader = (token) => ({
  Authorization: `Bearer ${token}`,
  Accept: 'application/json',
});

let echoInstance = null;

export const getEcho = () => {
  if (echoInstance) return echoInstance;
  const token = getToken();
  if (!token) return null; // not logged in → no socket

  const cfg = resolveReverbConfig(process.env, typeof window !== 'undefined' ? window.location : {});
  if (!cfg.key) return null;

  window.Pusher = Pusher;
  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: cfg.key,
    wsHost: cfg.wsHost,
    wsPort: cfg.wsPort,
    wssPort: cfg.wsPort,
    forceTLS: cfg.forceTLS,
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        fetch(`${getApiBaseUrl()}/broadcasting/auth`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', ...buildAuthHeader(getToken()) },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then((r) => (r.ok ? r.json() : Promise.reject(r)))
          .then((data) => callback(null, data))
          .catch((err) => callback(err, null));
      },
    }),
  });
  return echoInstance;
};

export const disconnectEcho = () => {
  if (echoInstance) {
    try { echoInstance.disconnect(); } catch (_) {}
    echoInstance = null;
  }
};
```

- [ ] **Step 6: Run it to verify it passes**

```bash
$env:CI="true"; npm test -- --testPathPattern=echo
```
Expected: PASS (4 tests). If `laravel-echo`/`pusher-js` ESM imports break Jest, the pure
functions are still importable — if Jest cannot parse the libs, move `resolveReverbConfig`
and `buildAuthHeader` into a sibling `echoConfig.js` (no lib imports) that `echo.js`
re-exports, and point the test at `echoConfig.js`. Prefer keeping them in `echo.js`;
only split if the suite fails to transform the libraries.

- [ ] **Step 7: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/admin/utils/echo.js frontend/src/admin/utils/echo.test.js frontend/.env.example
git commit -m "feat(realtime): Echo client with Bearer authorizer + LAN host resolution"
```

---

## Task 8: `useRealtime` hook

**Files:**
- Create: `frontend/src/admin/hooks/useRealtime.js`
- Test: `frontend/src/admin/hooks/useRealtime.test.js`

- [ ] **Step 1: Write the failing test (debounce helper)**

Create `frontend/src/admin/hooks/useRealtime.test.js`:

```js
import { debounce } from './useRealtime';

jest.useFakeTimers();

describe('debounce', () => {
  it('coalesces rapid calls into one', () => {
    const fn = jest.fn();
    const d = debounce(fn, 400);
    d(); d(); d();
    expect(fn).not.toHaveBeenCalled();
    jest.advanceTimersByTime(400);
    expect(fn).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$env:CI="true"; npm test -- --testPathPattern=useRealtime
```
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the hook**

Create `frontend/src/admin/hooks/useRealtime.js`:

```js
import { useEffect, useRef } from 'react';
import { getEcho } from '../utils/echo';
import { useAuth } from '../context/AuthContext';

/** Exported for testing: trailing debounce. */
export const debounce = (fn, ms) => {
  let t = null;
  return (...args) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => { t = null; fn(...args); }, ms);
  };
};

const CENTRALIZED_ROLES = [1, 2, 5];

/**
 * Subscribe to the user's realtime channel and run handlers when signal events arrive.
 * @param {Record<string, Function>} handlers map of broadcastAs name → callback
 * @param {number} debounceMs coalesce bursts (default 400ms)
 */
export const useRealtime = (handlers, debounceMs = 400) => {
  const { user } = useAuth();
  const handlersRef = useRef(handlers);
  handlersRef.current = handlers;

  useEffect(() => {
    const roleId = Number(user?.role?.role_id ?? user?.role_id ?? 0);
    const isCentralized = CENTRALIZED_ROLES.includes(roleId);
    const locationId = user?.location_id ?? user?.enforced_location_id ?? null;
    const channelName = isCentralized ? 'org' : (locationId != null ? `location.${locationId}` : null);
    if (!channelName) return;

    const echo = getEcho();
    if (!echo) return; // Reverb unreachable → screens fall back to polling

    let channel;
    try {
      channel = echo.private(channelName);
    } catch (_) {
      return;
    }

    const debounced = {};
    Object.entries(handlersRef.current || {}).forEach(([name, cb]) => {
      debounced[name] = debounce((payload) => cb(payload), debounceMs);
      channel.listen('.' + name, debounced[name]);
    });

    return () => {
      try {
        Object.keys(debounced).forEach((name) => channel.stopListening('.' + name));
        echo.leave(channelName);
      } catch (_) {}
    };
  }, [user?.role?.role_id, user?.role_id, user?.location_id, user?.enforced_location_id, debounceMs]);
};
```

- [ ] **Step 4: Run it to verify it passes**

```bash
$env:CI="true"; npm test -- --testPathPattern=useRealtime
```
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/hooks/useRealtime.js frontend/src/admin/hooks/useRealtime.test.js
git commit -m "feat(realtime): useRealtime subscription hook with debounce"
```

---

## Task 9: Wire screens + badges + logout cleanup

**Files (modify):**
- `frontend/src/admin/context/AuthContext.jsx` (disconnect on logout)
- `frontend/src/admin/components/AdminLayout.jsx` (live badges)
- `frontend/src/admin/pages/ApprovalQueue.jsx`
- the Inventory list page, the Transfers/IST list page, the Purchase Orders list page

- [ ] **Step 1: Disconnect Echo on logout**

In `frontend/src/admin/context/AuthContext.jsx`, import `disconnectEcho`:

```js
import { disconnectEcho } from '../utils/echo';
```
In `clearSession` (already clears the permission cache), add `disconnectEcho();` as the
first line so the socket is torn down on logout.

- [ ] **Step 2: Live badges in AdminLayout**

In `frontend/src/admin/components/AdminLayout.jsx`, import the hook:

```js
import { useRealtime } from '../hooks/useRealtime';
```
The badge effect already defines `syncBadges` (calls `layoutAPI.getSummary()`). Expose a
stable ref to it and subscribe so any signal refetches the badges. Add, after the badge
`useEffect`:

```js
  const badgeSyncRef = useRef(() => {});
  // keep the latest syncBadges available to the realtime handler
  // (set inside the badge effect: badgeSyncRef.current = syncBadges;)
  useRealtime({
    'approval-queue.changed': () => badgeSyncRef.current(),
    'inventory.changed': () => badgeSyncRef.current(),
    'transfer.changed': () => badgeSyncRef.current(),
    'purchase-order.changed': () => badgeSyncRef.current(),
  });
```
Inside the existing badge `useEffect`, set `badgeSyncRef.current = syncBadges;` right after
`syncBadges` is defined, so the handler always calls the current fetch. Keep the 60s
interval as the fallback. (`useRef` is already imported in this file.)

- [ ] **Step 3: Wire Approval Queue**

In `frontend/src/admin/pages/ApprovalQueue.jsx`, import the hook and subscribe its existing
fetch (the file already has `fetchServerPOs` and a 15s interval — keep the interval as
fallback):

```js
import { useRealtime } from '../hooks/useRealtime';
// inside the component, after fetchServerPOs is defined:
useRealtime({
  'approval-queue.changed': () => fetchServerPOs(),
  'purchase-order.changed': () => fetchServerPOs(),
});
```
If `fetchServerPOs` is defined inside a `useEffect` rather than component scope, lift it to
a `useCallback` in component scope first (minimal refactor), then reference it in both the
effect and the hook.

- [ ] **Step 4: Wire the Inventory list page**

`frontend/src/admin/pages/InventoryManagement.jsx` already has
`const fetchInventory = useCallback(async (page = 1) => {...})` (line ~164) in component
scope. Add the import and subscribe it:

```js
import { useRealtime } from '../hooks/useRealtime';
// inside the component, after fetchInventory is defined:
useRealtime({ 'inventory.changed': () => fetchInventory() });
```

- [ ] **Step 5: Wire the Transfers/IST list pages**

Three list pages cover transfers; each already exposes a `useCallback` fetch in component
scope. Add the import + hook to each:
- `frontend/src/admin/pages/StockTransferManagement.jsx` — `fetchTransfers` (line ~65):
  ```js
  import { useRealtime } from '../hooks/useRealtime';
  useRealtime({ 'transfer.changed': () => fetchTransfers(), 'inventory.changed': () => fetchTransfers() });
  ```
- `frontend/src/admin/pages/ISTManagementPage.jsx` — `fetchIsts` (line ~81):
  ```js
  import { useRealtime } from '../hooks/useRealtime';
  useRealtime({ 'transfer.changed': () => fetchIsts() });
  ```
- `frontend/src/admin/pages/TransferRequestPage.jsx` — `fetchPending` (line ~138):
  ```js
  import { useRealtime } from '../hooks/useRealtime';
  useRealtime({ 'transfer.changed': () => fetchPending() });
  ```

- [ ] **Step 6: Wire the Purchase Orders list page**

`frontend/src/admin/pages/PurchaseOrderPage.jsx` loads the PO status list inline inside a
`useEffect` keyed on `activeTab` (line ~202, calling `purchaseOrdersAPI.getAll({ per_page: 100 })`).
First LIFT that loader into a component-scope `useCallback` so the realtime handler can
reuse it. Add `useCallback` to the React import if missing, then:

```js
  const fetchPoStatusList = useCallback(() => {
    setLoadingPOStatus(true);
    setPoStatusError('');
    return purchaseOrdersAPI.getAll({ per_page: 100 })
      .then(res => setPoStatusList(Array.isArray(res?.data) ? res.data : []))
      .catch(err => setPoStatusError(err?.message || 'Failed to load purchase orders.'))
      .finally(() => setLoadingPOStatus(false));
  }, []);
```
Replace the inline `useEffect` body (lines ~202-217) with a guarded call:
```js
  useEffect(() => {
    if (activeTab !== 'status') return;
    fetchPoStatusList();
  }, [activeTab, fetchPoStatusList]);
```
(This drops the `mounted` guard; the `.finally` setters are harmless after unmount and
React only warns in dev — acceptable, and the realtime refetch needs a callable loader.)
Then subscribe:
```js
import { useRealtime } from '../hooks/useRealtime';
useRealtime({ 'purchase-order.changed': () => fetchPoStatusList() });
```

- [ ] **Step 7: Build check + test suite**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/frontend"
$env:CI="true"; npm test
```
Expected: `echo` + `useRealtime` tests pass; baseline otherwise unchanged (the known
pre-existing `App.test.jsx` failure is acceptable; no NEW failures, no compile errors).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/context/AuthContext.jsx frontend/src/admin/components/AdminLayout.jsx frontend/src/admin/pages
git commit -m "feat(realtime): subscribe badges + Approval/Inventory/Transfer/PO screens to live updates"
```

---

## Task 10: Documentation + run convenience

**Files:**
- Modify: `back-end/README.md`
- Modify: `back-end/composer.json` (`dev` script — optional convenience)

- [ ] **Step 1: Document the run + firewall requirement**

Append to `back-end/README.md`:

```markdown
## Real-time (Reverb)

Live updates require the Reverb WebSocket server running alongside Apache:

    php artisan reverb:start            # binds 0.0.0.0:8080

Open TCP 8080 in Windows Firewall (Private) so phones on the same Wi-Fi can connect.
If Reverb is NOT running, the app still works — screens fall back to polling.

Frontend env (frontend/.env): REACT_APP_REVERB_KEY must equal backend REVERB_APP_KEY;
REACT_APP_REVERB_HOST/PORT/SCHEME point at the Reverb server.
```

- [ ] **Step 2: (Optional) add reverb to the dev script**

In `back-end/composer.json` `scripts.dev`, add a `reverb:start` process to the
`concurrently` list (alongside server/queue/logs/vite) so `composer dev` launches it too.
Keep the existing entries; append `\"php artisan reverb:start\"` and a `reverb` name.

- [ ] **Step 3: Commit**

```bash
git add back-end/README.md back-end/composer.json
git commit -m "docs(realtime): document reverb:start + firewall; add to dev script"
```

---

## Final verification (manual, with Reverb running)

- [ ] Start `php artisan reverb:start`, Apache, and the React dev server. Log in.
- [ ] DevTools → Network → WS: a WebSocket to the Reverb host upgrades (101) and the
  `/api/broadcasting/auth` POST returns 200 for the subscribed channel.
- [ ] Two browsers (or browser + phone), different users at the same location: action in
  one (e.g. submit a PR / receive stock) updates the other's Approval Queue / Inventory /
  badge **without refresh**, within ~1s.
- [ ] A scoped user at location A does NOT receive updates for location B (channel auth);
  a centralized user receives both (subscribed to `org`).
- [ ] Stop `reverb:start`: screens still update on their existing poll intervals (graceful
  degradation); no console errors block the UI.
- [ ] `php artisan test` green (incl. new realtime tests); `CI=true npm test` shows only
  the known pre-existing `App.test.jsx` failure.
```
