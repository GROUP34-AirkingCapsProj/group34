# RBAC + Per-User Permission Override System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a database-driven permission system (catalog + role defaults + per-user overrides + audit log) with a resolver, a `permission:` middleware, and SA-only management UI — without changing the existing 74 `role:` routes (that migration is Phase 2).

**Architecture:** Four new tables (`permissions`, `role_permissions`, `user_permission_overrides`, `permission_audit_logs`). A `PermissionResolver` service answers `canUser(userId, feature, action)`: an active user override wins, else the role default, else deny. A seeder populates defaults from the reconciled matrix (spec §8). SA edits defaults/overrides through new React pages backed by a `PermissionController`. The global `auditor.readonly` middleware stays as the hard outer guard.

**Tech Stack:** Laravel 11 (`back-end/`), MySQL test DB `airkingV5_test`, PHPUnit feature tests with `DatabaseTransactions` + Sanctum acting-as. React 18 (`frontend/src/admin/`), `apiRequest()` service layer, Jest for pure helpers.

**Spec:** `docs/superpowers/specs/2026-06-11-rbac-permission-overrides-design.md`

---

## Conventions (read once before starting)

- **Roles (fixed ids):** `admin`=1, `inventory_analyst`=2, `branch_manager`=3, `warehouse_personnel`=4, `auditor`=5. Already seeded in dev and test DBs.
- **Test base:** extend `Tests\TestCase`, `use Illuminate\Foundation\Testing\DatabaseTransactions;`. Authenticate with `$this->actingAs($user, 'sanctum')`. Create users with `User::create([... 'role_id' => N, 'password_hash' => bcrypt('secret')])`.
- **Run backend tests:** from `back-end/`: `php artisan test tests/Feature/Permissions`.
- **Migrate the test DB** (PowerShell, from `back-end/`): `$env:DB_DATABASE='airkingV5_test'; php artisan migrate; Remove-Item Env:\DB_DATABASE`. Migrate dev DB with plain `php artisan migrate`.
- **API responses** use `App\Traits\ApiResponse`: `$this->success($data, $msg)`, `$this->error($msg, $code)`, `$this->forbidden($msg)`.
- **Frontend API calls** go through `apiRequest(path, opts)` in `frontend/src/admin/services/api.js`.
- **Commit after every task.** Branch is `backupV3`; commit there.

---

## File Structure

**Backend (create):**
- `back-end/database/migrations/2026_06_11_000001_create_permissions_table.php`
- `back-end/database/migrations/2026_06_11_000002_create_role_permissions_table.php`
- `back-end/database/migrations/2026_06_11_000003_create_user_permission_overrides_table.php`
- `back-end/database/migrations/2026_06_11_000004_create_permission_audit_logs_table.php`
- `back-end/app/Models/Permission.php`, `RolePermission.php`, `UserPermissionOverride.php`, `PermissionAuditLog.php`
- `back-end/app/Support/PermissionResolver.php`
- `back-end/app/Services/PermissionAdminService.php`
- `back-end/app/Http/Middleware/CheckPermission.php`
- `back-end/app/Http/Controllers/Api/PermissionController.php`
- `back-end/database/seeders/PermissionSeeder.php`
- `back-end/tests/Feature/Permissions/*` (one file per behavior area)

**Backend (modify):**
- `back-end/app/Models/User.php` — add `can()` + relations
- `back-end/bootstrap/app.php` — register `permission` alias
- `back-end/routes/api.php` — add SA-only permission-admin routes
- `back-end/database/seeders/DatabaseSeeder.php` — call `PermissionSeeder`

**Frontend (create):**
- `frontend/src/admin/pages/UserPermissionOverrides.jsx`
- `frontend/src/admin/pages/PermissionAuditLog.jsx`
- `frontend/src/admin/utils/permissions.js` (+ `permissions.test.js`)
- `frontend/src/admin/styles/permission_admin.css`

**Frontend (modify):**
- `frontend/src/admin/services/api.js` — add `permissionsAPI`
- `frontend/src/admin/pages/RolePermissions.jsx` — replace stub with the real editor
- `frontend/src/App.jsx` — routes for the two new pages
- `frontend/src/admin/components/Sidebar.jsx` + `frontend/src/admin/utils/roles.js` — menu + `ROUTE_ACCESS`

---

## Task 1: Create the four tables

**Files:**
- Create: the four migration files listed above
- Test: `back-end/tests/Feature/Permissions/SchemaTest.php`

- [ ] **Step 1: Write the migration — `permissions`**

Create `back-end/database/migrations/2026_06_11_000001_create_permissions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('permission_id');
            $table->string('feature', 64);
            $table->enum('action', ['create', 'read', 'update', 'archive', 'approve']);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['feature', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
```

- [ ] **Step 2: Write the migration — `role_permissions`**

Create `..._000002_create_role_permissions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id(); // surrogate PK so Eloquent updateOrCreate issues safe UPDATEs
            $table->unsignedInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->boolean('is_granted')->default(false);
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
            $table->foreign('permission_id')->references('permission_id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
```

- [ ] **Step 3: Write the migration — `user_permission_overrides`**

Create `..._000003_create_user_permission_overrides_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->bigIncrements('override_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->boolean('is_granted');
            $table->unsignedBigInteger('granted_by');
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'permission_id', 'revoked_at']);
            $table->foreign('permission_id')->references('permission_id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
```

- [ ] **Step 4: Write the migration — `permission_audit_logs`**

Create `..._000004_create_permission_audit_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('log_id');
            $table->unsignedBigInteger('changed_by');
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->unsignedInteger('target_role_id')->nullable();
            $table->unsignedBigInteger('permission_id');
            $table->boolean('old_value')->nullable();
            $table->boolean('new_value');
            $table->enum('change_type', ['role_default', 'user_override']);
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->index(['target_user_id', 'changed_at']);
            $table->index(['target_role_id', 'changed_at']);
            $table->foreign('permission_id')->references('permission_id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
    }
};
```

- [ ] **Step 5: Migrate dev and test databases**

Run from `back-end/` (PowerShell):
```powershell
php artisan migrate
$env:DB_DATABASE='airkingV5_test'; php artisan migrate; Remove-Item Env:\DB_DATABASE
```
Expected: four `Migrated` lines on each DB.

- [ ] **Step 6: Write a schema smoke test**

Create `back-end/tests/Feature/Permissions/SchemaTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_permission_tables_exist_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('permissions', ['permission_id', 'feature', 'action', 'description']));
        $this->assertTrue(Schema::hasColumns('role_permissions', ['role_id', 'permission_id', 'is_granted']));
        $this->assertTrue(Schema::hasColumns('user_permission_overrides', ['user_id', 'permission_id', 'is_granted', 'granted_by', 'revoked_at', 'reason']));
        $this->assertTrue(Schema::hasColumns('permission_audit_logs', ['changed_by', 'target_user_id', 'target_role_id', 'permission_id', 'old_value', 'new_value', 'change_type']));
    }
}
```

- [ ] **Step 7: Run the schema test**

Run: `php artisan test tests/Feature/Permissions/SchemaTest.php`
Expected: PASS (1 test).

- [ ] **Step 8: Commit**

```bash
git add back-end/database/migrations back-end/tests/Feature/Permissions/SchemaTest.php
git commit -m "feat(rbac): add permissions, role_permissions, overrides, audit log tables"
```

---

## Task 2: Eloquent models

**Files:**
- Create: `Permission.php`, `RolePermission.php`, `UserPermissionOverride.php`, `PermissionAuditLog.php` in `back-end/app/Models/`
- Modify: `back-end/app/Models/User.php`
- Test: `back-end/tests/Feature/Permissions/ModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Permissions/ModelTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\UserPermissionOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_models_persist_and_relate(): void
    {
        $perm = Permission::create(['feature' => 'demo_feature', 'action' => 'create', 'description' => 'x']);
        RolePermission::create(['role_id' => 1, 'permission_id' => $perm->permission_id, 'is_granted' => true]);

        $user = User::create([
            'username' => 'm_' . uniqid(), 'first_name' => 'M', 'last_name' => 'T',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 1,
        ]);

        $override = UserPermissionOverride::create([
            'user_id' => $user->user_id, 'permission_id' => $perm->permission_id,
            'is_granted' => false, 'granted_by' => $user->user_id, 'granted_at' => now(),
        ]);

        $this->assertEquals('demo_feature', $override->permission->feature);
        $this->assertEquals(1, RolePermission::where('permission_id', $perm->permission_id)->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/Permissions/ModelTest.php`
Expected: FAIL — `Class "App\Models\Permission" not found`.

- [ ] **Step 3: Create `Permission.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'permissions';
    protected $primaryKey = 'permission_id';

    protected $fillable = ['feature', 'action', 'description'];

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class, 'permission_id', 'permission_id');
    }
}
```

- [ ] **Step 4: Create `RolePermission.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $table = 'role_permissions';
    // Surrogate `id` PK (default). Uniqueness is enforced on (role_id, permission_id).

    protected $fillable = ['role_id', 'permission_id', 'is_granted'];

    protected $casts = ['is_granted' => 'boolean'];

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
```

- [ ] **Step 5: Create `UserPermissionOverride.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermissionOverride extends Model
{
    protected $table = 'user_permission_overrides';
    protected $primaryKey = 'override_id';

    protected $fillable = [
        'user_id', 'permission_id', 'is_granted', 'granted_by', 'granted_at', 'revoked_at', 'reason',
    ];

    protected $casts = [
        'is_granted' => 'boolean',
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
```

- [ ] **Step 6: Create `PermissionAuditLog.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionAuditLog extends Model
{
    protected $table = 'permission_audit_logs';
    protected $primaryKey = 'log_id';
    public $timestamps = false;

    protected $fillable = [
        'changed_by', 'target_user_id', 'target_role_id', 'permission_id',
        'old_value', 'new_value', 'change_type', 'reason', 'changed_at',
    ];

    protected $casts = [
        'old_value' => 'boolean',
        'new_value' => 'boolean',
        'changed_at' => 'datetime',
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id', 'user_id');
    }
}
```

- [ ] **Step 7: Run the test to confirm it passes**

Run: `php artisan test tests/Feature/Permissions/ModelTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Models back-end/tests/Feature/Permissions/ModelTest.php
git commit -m "feat(rbac): add permission Eloquent models"
```

---

## Task 3: PermissionResolver service + `User::can()`

This is the core. Override wins → role default → deny.

**Files:**
- Create: `back-end/app/Support/PermissionResolver.php`
- Modify: `back-end/app/Models/User.php`
- Test: `back-end/tests/Feature/Permissions/ResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Permissions/ResolverTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\PermissionResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function user(int $roleId = 2): User
    {
        return User::create([
            'username' => 'r_' . uniqid(), 'first_name' => 'R', 'last_name' => 'T',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function perm(string $f, string $a): Permission
    {
        return Permission::create(['feature' => $f, 'action' => $a, 'description' => 'x']);
    }

    public function test_role_default_grants_when_no_override(): void
    {
        $u = $this->user(2);
        $p = $this->perm('demo', 'create');
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);

        $this->assertTrue(app(PermissionResolver::class)->canUser($u->user_id, 'demo', 'create'));
    }

    public function test_active_override_beats_role_default(): void
    {
        $u = $this->user(2);
        $p = $this->perm('demo', 'create');
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);
        UserPermissionOverride::create([
            'user_id' => $u->user_id, 'permission_id' => $p->permission_id,
            'is_granted' => false, 'granted_by' => $u->user_id, 'granted_at' => now(),
        ]);

        $this->assertFalse(app(PermissionResolver::class)->canUser($u->user_id, 'demo', 'create'));
    }

    public function test_revoked_override_is_ignored_and_role_default_applies(): void
    {
        $u = $this->user(2);
        $p = $this->perm('demo', 'create');
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);
        UserPermissionOverride::create([
            'user_id' => $u->user_id, 'permission_id' => $p->permission_id,
            'is_granted' => false, 'granted_by' => $u->user_id, 'granted_at' => now(), 'revoked_at' => now(),
        ]);

        $this->assertTrue(app(PermissionResolver::class)->canUser($u->user_id, 'demo', 'create'));
    }

    public function test_deny_by_default_when_no_rows(): void
    {
        $u = $this->user(2);
        $this->assertFalse(app(PermissionResolver::class)->canUser($u->user_id, 'nope', 'create'));
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/Permissions/ResolverTest.php`
Expected: FAIL — `Class "App\Support\PermissionResolver" not found`.

- [ ] **Step 3: Create `PermissionResolver.php`**

```php
<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;

class PermissionResolver
{
    /** Per-request memo of feature.action => permission_id. */
    private array $permIdCache = [];

    public function permissionId(string $feature, string $action): ?int
    {
        $key = $feature . '.' . $action;
        if (! array_key_exists($key, $this->permIdCache)) {
            $this->permIdCache[$key] = Permission::where('feature', $feature)
                ->where('action', $action)
                ->value('permission_id');
        }
        return $this->permIdCache[$key] ? (int) $this->permIdCache[$key] : null;
    }

    public function canUser(int $userId, string $feature, string $action): bool
    {
        $permId = $this->permissionId($feature, $action);
        if ($permId === null) {
            return false; // unknown permission → deny
        }

        $override = UserPermissionOverride::where('user_id', $userId)
            ->where('permission_id', $permId)
            ->whereNull('revoked_at')
            ->first();
        if ($override !== null) {
            return (bool) $override->is_granted;
        }

        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        $rp = RolePermission::where('role_id', $user->role_id)
            ->where('permission_id', $permId)
            ->first();

        return $rp !== null ? (bool) $rp->is_granted : false;
    }

    /**
     * Effective grants for a user: role defaults overlaid with active overrides.
     * Returns ['feature.action' => bool].
     */
    public function effectivePermissionsFor(int $userId): array
    {
        $user = User::find($userId);
        if (! $user) {
            return [];
        }

        $perms = Permission::all()->keyBy('permission_id');
        $effective = [];

        $defaults = RolePermission::where('role_id', $user->role_id)->get();
        foreach ($defaults as $rp) {
            if ($perm = $perms->get($rp->permission_id)) {
                $effective[$perm->feature . '.' . $perm->action] = (bool) $rp->is_granted;
            }
        }

        $overrides = UserPermissionOverride::where('user_id', $userId)->whereNull('revoked_at')->get();
        foreach ($overrides as $ov) {
            if ($perm = $perms->get($ov->permission_id)) {
                $effective[$perm->feature . '.' . $perm->action] = (bool) $ov->is_granted;
            }
        }

        return $effective;
    }
}
```

- [ ] **Step 4: Add `can()` to the User model**

In `back-end/app/Models/User.php`, add these methods inside the class (e.g. after `hasStrictLocation()`):

```php
    /**
     * Resolve a feature/action permission for this user (override → role default → deny).
     */
    public function can($feature, $action = null): bool
    {
        // Permission path only when called as can('feature', 'action') with two strings.
        // Any other shape (ability string, or array $arguments) delegates to the framework
        // Gate so existing/internal can() calls are unaffected.
        if (is_string($feature) && is_string($action)) {
            return app(\App\Support\PermissionResolver::class)->canUser((int) $this->user_id, $feature, $action);
        }
        return parent::can($feature, $action ?? []);
    }
```

> Note: this overloads the framework `can()`. Only the two-string form is the permission check; everything else delegates to the parent Gate, so existing/internal calls are unaffected.

- [ ] **Step 5: Run the test to confirm it passes**

Run: `php artisan test tests/Feature/Permissions/ResolverTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Support/PermissionResolver.php back-end/app/Models/User.php back-end/tests/Feature/Permissions/ResolverTest.php
git commit -m "feat(rbac): add PermissionResolver and User::can(feature, action)"
```

---

## Task 4: Permission matrix + seeder

Seeds the `permissions` catalog and `role_permissions` defaults from the reconciled matrix (spec §8). Idempotent.

**Files:**
- Create: `back-end/database/seeders/PermissionSeeder.php`
- Modify: `back-end/database/seeders/DatabaseSeeder.php`
- Test: `back-end/tests/Feature/Permissions/SeederTest.php`

- [ ] **Step 1: Write the failing test (asserts the reconciled cells)**

Create `back-end/tests/Feature/Permissions/SeederTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use DatabaseTransactions;

    private array $roleId = [
        'admin' => 1, 'inventory_analyst' => 2, 'branch_manager' => 3,
        'warehouse_personnel' => 4, 'auditor' => 5,
    ];

    private function granted(string $feature, string $action, int $roleId): bool
    {
        $permId = Permission::where('feature', $feature)->where('action', $action)->value('permission_id');
        if ($permId === null) {
            return false;
        }
        $rp = RolePermission::where('role_id', $roleId)->where('permission_id', $permId)->first();
        return $rp !== null && $rp->is_granted;
    }

    public function test_seeds_reconciled_matrix(): void
    {
        (new PermissionSeeder())->run();

        // Theme B: archive is SA-only everywhere.
        $this->assertTrue($this->granted('item_master_management', 'archive', $this->roleId['admin']));
        $this->assertFalse($this->granted('item_master_management', 'archive', $this->roleId['inventory_analyst']));

        // Theme C: inventory_transfers.approve = SA, IA only.
        $this->assertTrue($this->granted('inventory_transfers', 'approve', $this->roleId['inventory_analyst']));
        $this->assertFalse($this->granted('inventory_transfers', 'approve', $this->roleId['branch_manager']));
        $this->assertFalse($this->granted('inventory_transfers', 'approve', $this->roleId['warehouse_personnel']));

        // Theme D: pos_sales_recording.create = SA, BM, WP.
        $this->assertTrue($this->granted('pos_sales_recording', 'create', $this->roleId['warehouse_personnel']));
        $this->assertFalse($this->granted('pos_sales_recording', 'create', $this->roleId['inventory_analyst']));

        // Theme E: purchase_order_creation writes = SA, IA only.
        $this->assertTrue($this->granted('purchase_order_creation', 'create', $this->roleId['inventory_analyst']));
        $this->assertFalse($this->granted('purchase_order_creation', 'create', $this->roleId['branch_manager']));

        // Theme A: auditor has no write grants (e.g. supplier create) but keeps read.
        $this->assertFalse($this->granted('supplier_management', 'create', $this->roleId['auditor']));
        $this->assertTrue($this->granted('supplier_management', 'read', $this->roleId['auditor']));

        // Stage-2 Bucket 6 decisions:
        // 6a — barcode_assignment.create keeps BM (SA,BM,WP).
        $this->assertTrue($this->granted('barcode_assignment', 'create', $this->roleId['branch_manager']));
        // 6g/6h — inventory_transfers.create keeps IA (SA,IA,BM,WP).
        $this->assertTrue($this->granted('inventory_transfers', 'create', $this->roleId['inventory_analyst']));
        $this->assertTrue($this->granted('inventory_transfers', 'create', $this->roleId['warehouse_personnel']));
        // 6l — location_based_inventory.create is SA,BM only (IA dropped).
        $this->assertTrue($this->granted('location_based_inventory', 'create', $this->roleId['branch_manager']));
        $this->assertFalse($this->granted('location_based_inventory', 'create', $this->roleId['inventory_analyst']));
        // 6r — purchase_order_creation.read adds BM (SA,IA,BM,AU).
        $this->assertTrue($this->granted('purchase_order_creation', 'read', $this->roleId['branch_manager']));

        // Re-running is idempotent (no duplicate permission rows).
        (new PermissionSeeder())->run();
        $this->assertEquals(1, Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/Permissions/SeederTest.php`
Expected: FAIL — `Class "Database\Seeders\PermissionSeeder" not found`.

- [ ] **Step 3: Create `PermissionSeeder.php` with the full reconciled matrix**

```php
<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roleIds = DB::table('roles')->pluck('role_id', 'role_name'); // slug => id

        foreach ($this->matrix() as $feature => $actions) {
            foreach ($actions as $action => $grantedRoles) {
                $permission = Permission::firstOrCreate(
                    ['feature' => $feature, 'action' => $action],
                    ['description' => ucfirst($action) . ' access for ' . str_replace('_', ' ', $feature)]
                );

                foreach ($roleIds as $slug => $roleId) {
                    RolePermission::updateOrCreate(
                        ['role_id' => $roleId, 'permission_id' => $permission->permission_id],
                        ['is_granted' => in_array($slug, $grantedRoles, true)]
                    );
                }
            }
        }
    }

    /**
     * Canonical reconciled matrix (spec §8). Role slugs:
     * admin, inventory_analyst, branch_manager, warehouse_personnel, auditor.
     */
    private function matrix(): array
    {
        $SA = 'admin';
        $IA = 'inventory_analyst';
        $BM = 'branch_manager';
        $WP = 'warehouse_personnel';
        $AU = 'auditor';

        return [
            // --- Group I ---
            'centralized_inventory' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'automated_inventory_monitoring' => [
                'create' => [$SA, $IA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $IA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM, $WP],
            ],
            'location_based_inventory' => [
                'create' => [$SA, $BM], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $BM], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM],
            ],
            'barcode_inventory_processing' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],
            'inventory_receiving' => [
                'create' => [$SA, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],
            'inventory_issuance' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],
            'inventory_transfers' => [
                'create' => [$SA, $IA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $IA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'inventory_adjustments' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],

            // --- Group II ---
            'item_master_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'category_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'brand_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'uom_management' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'barcode_assignment' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $WP],
            ],

            // --- Group III ---
            'user_account_management' => [
                'create' => [$SA], 'read' => [$SA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'role_assignment' => [
                'create' => [$SA], 'read' => [$SA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'rbac_enforcement' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group IV ---
            'role_dashboards' => ['read' => [$SA, $IA, $BM, $WP, $AU]],
            'summary_cards' => ['read' => [$SA, $IA, $BM, $WP, $AU]],
            'quick_actions' => ['create' => [$SA, $IA, $BM, $WP], 'update' => [$SA, $IA, $BM, $WP]],
            'notification_panel' => ['read' => [$SA, $IA, $BM, $WP, $AU]],

            // --- Group V ---
            'reorder_monitoring' => ['read' => [$SA, $IA, $BM, $AU]],
            'safety_stock' => ['read' => [$SA, $IA, $BM, $AU]],
            'consumption_rate' => ['read' => [$SA, $IA, $BM, $AU]],
            'low_stock_prediction' => ['read' => [$SA, $IA, $BM, $AU]],
            'restock_recommendation' => ['read' => [$SA, $IA, $BM, $AU]],
            'restock_quantity' => ['read' => [$SA, $IA, $BM, $AU]],
            'restock_approval' => ['approve' => [$SA]],
            'restock_history' => ['read' => [$SA, $IA, $BM, $AU]],

            // --- Group VI ---
            'supplier_management' => [
                'create' => [$SA, $IA, $BM], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA, $BM], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM],
            ],
            'item_supplier_mapping' => [
                'create' => [$SA, $IA, $BM], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA, $BM], 'archive' => [$SA], 'approve' => [$SA, $IA, $BM],
            ],
            'purchase_order_creation' => [
                'create' => [$SA, $IA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $IA], 'archive' => [$SA], 'approve' => [$SA, $IA],
            ],
            'purchase_order_approval' => ['approve' => [$SA]],
            'po_status_tracking' => ['read' => [$SA, $IA, $BM, $WP, $AU]],
            'delivery_receiving' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM, $WP],
            ],

            // --- Group VII ---
            'pos_sales_recording' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'barcode_scanning_sales' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'sales_validation' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'customer_info_capture' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'delivery_receipt_generation' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'pos_transaction_logs' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group VIII ---
            'transaction_validation' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'transaction_approval' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'transaction_rejection' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group IX ---
            'audit_trail_viewing' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'transaction_logs_audit' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'login_logs' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'exception_detection' => [
                'create' => [$SA], 'read' => [$SA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group X ---
            'stock_level_analysis' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'consumption_analysis' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'inventory_turnover_analysis' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group XI ---
            'inventory_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'transaction_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'audit_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'export_reports' => [
                'create' => [$SA], 'read' => [$SA, $IA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],

            // --- Group XII ---
            'document_upload' => [
                'create' => [$SA, $BM, $WP], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA, $BM, $WP], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'document_verification' => [
                'create' => [$SA, $BM], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA, $BM], 'archive' => [$SA], 'approve' => [$SA, $BM],
            ],
            'document_viewing' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $WP, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'data_archiving' => [
                'create' => [$SA], 'read' => [$SA, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
            'archive_viewing' => [
                'create' => [$SA], 'read' => [$SA, $IA, $BM, $AU], 'update' => [$SA], 'archive' => [$SA], 'approve' => [$SA],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run the seeder test to confirm it passes**

Run: `php artisan test tests/Feature/Permissions/SeederTest.php`
Expected: PASS.

- [ ] **Step 5: Register the seeder in `DatabaseSeeder` and seed both DBs**

In `back-end/database/seeders/DatabaseSeeder.php`, add `$this->call(PermissionSeeder::class);` to the `run()` method (after the roles seeder call). Then:
```powershell
php artisan db:seed --class=PermissionSeeder
$env:DB_DATABASE='airkingV5_test'; php artisan db:seed --class=PermissionSeeder; Remove-Item Env:\DB_DATABASE
```
Expected: no errors; permissions + role_permissions populated on both DBs.

- [ ] **Step 6: Commit**

```bash
git add back-end/database/seeders/PermissionSeeder.php back-end/database/seeders/DatabaseSeeder.php back-end/tests/Feature/Permissions/SeederTest.php
git commit -m "feat(rbac): seed reconciled permission matrix (themes A-E applied)"
```

---

## Task 5: `CheckPermission` middleware

Built now per spec §3, even though route migration is Phase 2.

**Files:**
- Create: `back-end/app/Http/Middleware/CheckPermission.php`
- Modify: `back-end/bootstrap/app.php`
- Test: `back-end/tests/Feature/Permissions/MiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Permissions/MiddlewareTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    private function user(int $roleId): User
    {
        return User::create([
            'username' => 'mw_' . uniqid(), 'first_name' => 'M', 'last_name' => 'W',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware(['auth:sanctum', 'permission:demo_mw,create'])
            ->get('/_test/demo-mw', fn () => response()->json(['ok' => true]));
    }

    public function test_grants_pass_and_denies_403(): void
    {
        $p = Permission::create(['feature' => 'demo_mw', 'action' => 'create', 'description' => 'x']);
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);
        RolePermission::create(['role_id' => 3, 'permission_id' => $p->permission_id, 'is_granted' => false]);

        $this->actingAs($this->user(2), 'sanctum')->getJson('/_test/demo-mw')->assertOk();
        $this->actingAs($this->user(3), 'sanctum')->getJson('/_test/demo-mw')->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/Permissions/MiddlewareTest.php`
Expected: FAIL — middleware alias `permission` not defined (or class missing).

- [ ] **Step 3: Create `CheckPermission.php`**

```php
<?php

namespace App\Http\Middleware;

use App\Support\PermissionResolver;
use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function __construct(private PermissionResolver $resolver) {}

    /**
     * Usage: permission:feature,action
     */
    public function handle(Request $request, Closure $next, string $feature, string $action)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (! $this->resolver->canUser((int) $user->user_id, $feature, $action)) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorized. Missing permission: {$feature}.{$action}",
            ], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `back-end/bootstrap/app.php`, add to the `$middleware->alias([...])` array:

```php
            'permission' => \App\Http\Middleware\CheckPermission::class,
```

- [ ] **Step 5: Run the middleware test to confirm it passes**

Run: `php artisan test tests/Feature/Permissions/MiddlewareTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Middleware/CheckPermission.php back-end/bootstrap/app.php back-end/tests/Feature/Permissions/MiddlewareTest.php
git commit -m "feat(rbac): add permission: middleware (Phase-2 enforcement vehicle)"
```

---

## Task 6: `PermissionAdminService` (writes + audit)

Centralizes the transactional logic for changing a role default or a user override and writing the audit row.

**Files:**
- Create: `back-end/app/Services/PermissionAdminService.php`
- Test: `back-end/tests/Feature/Permissions/AdminServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Permissions/AdminServiceTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\PermissionAuditLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionAdminService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'username' => 'a_' . uniqid(), 'first_name' => 'A', 'last_name' => 'D',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 1,
        ]);
    }

    public function test_set_role_default_writes_value_and_audit(): void
    {
        $admin = $this->admin();
        $p = Permission::create(['feature' => 'svc', 'action' => 'create', 'description' => 'x']);
        RolePermission::create(['role_id' => 3, 'permission_id' => $p->permission_id, 'is_granted' => false]);

        app(PermissionAdminService::class)->setRoleDefault($admin, 3, $p->permission_id, true);

        $this->assertTrue(RolePermission::where('role_id', 3)->where('permission_id', $p->permission_id)->first()->is_granted);
        $log = PermissionAuditLog::where('change_type', 'role_default')->where('permission_id', $p->permission_id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(3, $log->target_role_id);
        $this->assertFalse($log->old_value);
        $this->assertTrue($log->new_value);
    }

    public function test_set_user_override_revokes_prior_active_and_logs(): void
    {
        $admin = $this->admin();
        $target = User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 3,
        ]);
        $p = Permission::create(['feature' => 'svc', 'action' => 'update', 'description' => 'x']);

        $svc = app(PermissionAdminService::class);
        $svc->setUserOverride($admin, $target->user_id, $p->permission_id, true, 'first');
        $svc->setUserOverride($admin, $target->user_id, $p->permission_id, false, 'second');

        $active = UserPermissionOverride::where('user_id', $target->user_id)
            ->where('permission_id', $p->permission_id)->whereNull('revoked_at')->get();
        $this->assertCount(1, $active);
        $this->assertFalse((bool) $active->first()->is_granted);
        $this->assertEquals(2, PermissionAuditLog::where('change_type', 'user_override')->where('permission_id', $p->permission_id)->count());
    }

    public function test_clear_user_override_revokes_and_logs_back_to_default(): void
    {
        $admin = $this->admin();
        $target = User::create([
            'username' => 'c_' . uniqid(), 'first_name' => 'C', 'last_name' => 'U',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 3,
        ]);
        $p = Permission::create(['feature' => 'svc', 'action' => 'approve', 'description' => 'x']);
        RolePermission::create(['role_id' => 3, 'permission_id' => $p->permission_id, 'is_granted' => true]);

        $svc = app(PermissionAdminService::class);
        $svc->setUserOverride($admin, $target->user_id, $p->permission_id, false, 'deny it');
        $svc->clearUserOverride($admin, $target->user_id, $p->permission_id, 'reset');

        $this->assertEquals(0, UserPermissionOverride::where('user_id', $target->user_id)
            ->where('permission_id', $p->permission_id)->whereNull('revoked_at')->count());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/Permissions/AdminServiceTest.php`
Expected: FAIL — `Class "App\Services\PermissionAdminService" not found`.

- [ ] **Step 3: Create `PermissionAdminService.php`**

```php
<?php

namespace App\Services;

use App\Models\PermissionAuditLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;

class PermissionAdminService
{
    public function setRoleDefault(User $actor, int $roleId, int $permissionId, bool $isGranted): void
    {
        DB::transaction(function () use ($actor, $roleId, $permissionId, $isGranted) {
            $rp = RolePermission::where('role_id', $roleId)->where('permission_id', $permissionId)->first();
            $old = $rp?->is_granted;

            RolePermission::updateOrCreate(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['is_granted' => $isGranted]
            );

            PermissionAuditLog::create([
                'changed_by' => $actor->user_id,
                'target_user_id' => null,
                'target_role_id' => $roleId,
                'permission_id' => $permissionId,
                'old_value' => $old,
                'new_value' => $isGranted,
                'change_type' => 'role_default',
                'reason' => null,
                'changed_at' => now(),
            ]);
        });
    }

    public function setUserOverride(User $actor, int $userId, int $permissionId, bool $isGranted, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $userId, $permissionId, $isGranted, $reason) {
            $prior = UserPermissionOverride::where('user_id', $userId)
                ->where('permission_id', $permissionId)->whereNull('revoked_at')->first();
            $old = $prior?->is_granted;

            if ($prior) {
                $prior->update(['revoked_at' => now()]);
            }

            UserPermissionOverride::create([
                'user_id' => $userId,
                'permission_id' => $permissionId,
                'is_granted' => $isGranted,
                'granted_by' => $actor->user_id,
                'granted_at' => now(),
                'revoked_at' => null,
                'reason' => $reason,
            ]);

            PermissionAuditLog::create([
                'changed_by' => $actor->user_id,
                'target_user_id' => $userId,
                'target_role_id' => null,
                'permission_id' => $permissionId,
                'old_value' => $old,
                'new_value' => $isGranted,
                'change_type' => 'user_override',
                'reason' => $reason,
                'changed_at' => now(),
            ]);
        });
    }

    public function clearUserOverride(User $actor, int $userId, int $permissionId, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $userId, $permissionId, $reason) {
            $prior = UserPermissionOverride::where('user_id', $userId)
                ->where('permission_id', $permissionId)->whereNull('revoked_at')->first();
            if (! $prior) {
                return;
            }
            $old = $prior->is_granted;
            $prior->update(['revoked_at' => now()]);

            PermissionAuditLog::create([
                'changed_by' => $actor->user_id,
                'target_user_id' => $userId,
                'target_role_id' => null,
                'permission_id' => $permissionId,
                'old_value' => $old,
                'new_value' => $old, // cleared → reverts to role default; record the revoke event
                'change_type' => 'user_override',
                'reason' => $reason,
                'changed_at' => now(),
            ]);
        });
    }
}
```

- [ ] **Step 4: Run the service test to confirm it passes**

Run: `php artisan test tests/Feature/Permissions/AdminServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/PermissionAdminService.php back-end/tests/Feature/Permissions/AdminServiceTest.php
git commit -m "feat(rbac): add PermissionAdminService for transactional changes + audit"
```

---

## Task 7: `PermissionController` + SA-only routes

**Files:**
- Create: `back-end/app/Http/Controllers/Api/PermissionController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Permissions/ControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Permissions/ControllerTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function user(int $roleId): User
    {
        return User::create([
            'username' => 'ct_' . uniqid(), 'first_name' => 'C', 'last_name' => 'T',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->user(2), 'sanctum')->getJson('/api/role-permissions')->assertStatus(403);
    }

    public function test_admin_lists_and_updates_role_default(): void
    {
        (new PermissionSeeder())->run();
        $admin = $this->user(1);

        $this->actingAs($admin, 'sanctum')->getJson('/api/permissions')
            ->assertOk()->assertJsonStructure(['data' => [['permission_id', 'feature', 'action']]]);

        $permId = Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');

        $this->actingAs($admin, 'sanctum')->putJson('/api/role-permissions', [
            'changes' => [['role_id' => 3, 'permission_id' => $permId, 'is_granted' => true]],
        ])->assertOk();

        $this->assertTrue(RolePermission::where('role_id', 3)->where('permission_id', $permId)->first()->is_granted);
    }

    public function test_admin_sets_user_override_requires_reason(): void
    {
        (new PermissionSeeder())->run();
        $admin = $this->user(1);
        $target = $this->user(3);
        $permId = Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');

        // Missing reason → 422
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'changes' => [['permission_id' => $permId, 'is_granted' => true]],
        ])->assertStatus(422);

        // With reason → 200
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'reason' => 'temporary grant',
            'changes' => [['permission_id' => $permId, 'is_granted' => true]],
        ])->assertOk();
    }

    public function test_admin_views_audit_log(): void
    {
        (new PermissionSeeder())->run();
        $admin = $this->user(1);
        $target = $this->user(3);
        $permId = Permission::where('feature', 'supplier_management')->where('action', 'create')->value('permission_id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'reason' => 'audit me', 'changes' => [['permission_id' => $permId, 'is_granted' => false]],
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')->getJson('/api/permission-audit-logs')
            ->assertOk()->assertJsonStructure(['data']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/Permissions/ControllerTest.php`
Expected: FAIL — 404/route not defined.

- [ ] **Step 3: Create `PermissionController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\PermissionAuditLog;
use App\Models\RolePermission;
use App\Models\UserPermissionOverride;
use App\Services\PermissionAdminService;
use App\Support\PermissionResolver;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PermissionAdminService $admin,
        private PermissionResolver $resolver,
    ) {}

    /** Full permission catalog. */
    public function index()
    {
        return $this->success(
            Permission::orderBy('feature')->orderBy('action')->get(),
            'Permissions catalog'
        );
    }

    /** All role default grants. */
    public function roleDefaults()
    {
        return $this->success(
            RolePermission::get(['role_id', 'permission_id', 'is_granted']),
            'Role permission defaults'
        );
    }

    /** Update one or more role default cells. */
    public function updateRoleDefaults(Request $request)
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.role_id' => ['required', 'integer'],
            'changes.*.permission_id' => ['required', 'integer', 'exists:permissions,permission_id'],
            'changes.*.is_granted' => ['required', 'boolean'],
        ]);

        foreach ($data['changes'] as $change) {
            $this->admin->setRoleDefault($request->user(), (int) $change['role_id'], (int) $change['permission_id'], (bool) $change['is_granted']);
        }

        return $this->success(null, 'Role permissions updated');
    }

    /** A user's effective permissions, role defaults, and active overrides. */
    public function userPermissions(Request $request, $userId)
    {
        $overrides = UserPermissionOverride::where('user_id', $userId)
            ->whereNull('revoked_at')->get(['permission_id', 'is_granted']);

        return $this->success([
            'effective' => $this->resolver->effectivePermissionsFor((int) $userId),
            'overrides' => $overrides,
        ], 'User permissions');
    }

    /** Set or clear overrides for a user. A reason is required. */
    public function updateUserPermissions(Request $request, $userId)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.permission_id' => ['required', 'integer', 'exists:permissions,permission_id'],
            'changes.*.is_granted' => ['nullable', 'boolean'], // null = clear override
        ]);

        foreach ($data['changes'] as $change) {
            $permId = (int) $change['permission_id'];
            if (array_key_exists('is_granted', $change) && $change['is_granted'] === null) {
                $this->admin->clearUserOverride($request->user(), (int) $userId, $permId, $data['reason']);
            } else {
                $this->admin->setUserOverride($request->user(), (int) $userId, $permId, (bool) $change['is_granted'], $data['reason']);
            }
        }

        return $this->success(null, 'User permissions updated');
    }

    /** Filterable audit feed. */
    public function auditLogs(Request $request)
    {
        $query = PermissionAuditLog::with(['permission', 'changedBy', 'targetUser'])
            ->orderByDesc('changed_at');

        if ($request->filled('target_user_id')) {
            $query->where('target_user_id', $request->target_user_id);
        }
        if ($request->filled('target_role_id')) {
            $query->where('target_role_id', $request->target_role_id);
        }
        if ($request->filled('change_type')) {
            $query->where('change_type', $request->change_type);
        }
        if ($request->filled('feature') || $request->filled('action')) {
            $query->whereHas('permission', function ($q) use ($request) {
                if ($request->filled('feature')) {
                    $q->where('feature', $request->feature);
                }
                if ($request->filled('action')) {
                    $q->where('action', $request->action);
                }
            });
        }
        if ($request->filled('date_from')) {
            $query->where('changed_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('changed_at', '<=', $request->date_to);
        }

        return $this->success($query->paginate($request->input('per_page', 50)), 'Permission audit logs');
    }
}
```

- [ ] **Step 4: Register the routes**

In `back-end/routes/api.php`, add the import near the other `use` statements:
```php
use App\Http\Controllers\Api\PermissionController;
```
Then inside the `auth:sanctum` group (e.g. right after the existing User Management `role:admin` block ending at line 129), add:

```php
    /*
    |--------------------------------------------------------------------------
    | PERMISSION MANAGEMENT (ADMIN ONLY)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::get('/role-permissions', [PermissionController::class, 'roleDefaults']);
        Route::put('/role-permissions', [PermissionController::class, 'updateRoleDefaults']);
        Route::get('/users/{userId}/permissions', [PermissionController::class, 'userPermissions']);
        Route::put('/users/{userId}/permissions', [PermissionController::class, 'updateUserPermissions']);
        Route::get('/permission-audit-logs', [PermissionController::class, 'auditLogs']);
    });
```

> The `auditor.readonly` global guard already blocks auditors from the `PUT` routes; `role:admin` restricts all six to SA.

- [ ] **Step 5: Run the controller test to confirm it passes**

Run: `php artisan test tests/Feature/Permissions/ControllerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the whole permission suite**

Run: `php artisan test tests/Feature/Permissions`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/PermissionController.php back-end/routes/api.php back-end/tests/Feature/Permissions/ControllerTest.php
git commit -m "feat(rbac): add PermissionController + SA-only management routes"
```

---

## Task 8: Frontend `permissionsAPI` service

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the `permissionsAPI` object**

Append near the other API objects in `frontend/src/admin/services/api.js` (after `auditAPI`, matching the existing `apiRequest` style):

```javascript
/* ======================
   PERMISSIONS API (RBAC management — SA only)
====================== */
export const permissionsAPI = {
  catalog: async () => apiRequest('/permissions'),
  getRoleDefaults: async () => apiRequest('/role-permissions'),
  updateRoleDefaults: async (changes) =>
    apiRequest('/role-permissions', { method: 'PUT', body: JSON.stringify({ changes }) }),
  getUserPermissions: async (userId) => apiRequest(`/users/${userId}/permissions`),
  updateUserPermissions: async (userId, reason, changes) =>
    apiRequest(`/users/${userId}/permissions`, {
      method: 'PUT',
      body: JSON.stringify({ reason, changes }),
    }),
  getAuditLogs: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(query ? `/permission-audit-logs?${query}` : '/permission-audit-logs');
  },
};
```

- [ ] **Step 2: Verify the build compiles**

Run from `frontend/`: `npm run build` (or confirm the dev server hot-reloads without error).
Expected: no import/syntax errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(rbac): add permissionsAPI service methods"
```

---

## Task 9: Pure helper + Page A (Role Permissions Editor)

The 12 groups must be presented consistently across pages, so first add a shared helper, then the editor.

**Files:**
- Create: `frontend/src/admin/utils/permissions.js`, `frontend/src/admin/utils/permissions.test.js`, `frontend/src/admin/styles/permission_admin.css`
- Modify: `frontend/src/admin/pages/RolePermissions.jsx`

- [ ] **Step 1: Write the failing helper test**

Create `frontend/src/admin/utils/permissions.test.js`:

```javascript
import { groupByFeatureGroup, FEATURE_GROUPS, ROLE_COLUMNS, keyOf } from './permissions';

test('keyOf builds feature.action key', () => {
  expect(keyOf('inventory_transfers', 'approve')).toBe('inventory_transfers.approve');
});

test('groupByFeatureGroup buckets known features and ignores unknown', () => {
  const perms = [
    { permission_id: 1, feature: 'inventory_transfers', action: 'approve' },
    { permission_id: 2, feature: 'made_up_feature', action: 'read' },
  ];
  const grouped = groupByFeatureGroup(perms);
  const transfersGroup = grouped.find((g) =>
    g.features.some((f) => f.feature === 'inventory_transfers'));
  expect(transfersGroup).toBeTruthy();
  // Unknown features fall into the catch-all "Other" group, not dropped.
  const other = grouped.find((g) => g.label === 'Other');
  expect(other.features.some((f) => f.feature === 'made_up_feature')).toBe(true);
});

test('ROLE_COLUMNS lists the five roles with ids', () => {
  expect(ROLE_COLUMNS.map((r) => r.id)).toEqual([1, 2, 3, 4, 5]);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run from `frontend/`: `npm test -- permissions.test.js --watchAll=false`
Expected: FAIL — module `./permissions` not found.

- [ ] **Step 3: Create `permissions.js`**

```javascript
/**
 * Shared helpers for the RBAC management pages.
 * Feature groups mirror the spec (docs/superpowers/specs/2026-06-11-rbac-permission-overrides-design.md §8).
 */

export const ROLE_COLUMNS = [
  { id: 1, slug: 'admin', label: 'SA' },
  { id: 2, slug: 'inventory_analyst', label: 'IA' },
  { id: 3, slug: 'branch_manager', label: 'BM' },
  { id: 4, slug: 'warehouse_personnel', label: 'WP' },
  { id: 5, slug: 'auditor', label: 'AU' },
];

export const ACTIONS = ['create', 'read', 'update', 'archive', 'approve'];

export const keyOf = (feature, action) => `${feature}.${action}`;

export const FEATURE_GROUPS = [
  { label: 'I. Core Inventory & Operations', features: ['centralized_inventory', 'automated_inventory_monitoring', 'location_based_inventory', 'barcode_inventory_processing', 'inventory_receiving', 'inventory_issuance', 'inventory_transfers', 'inventory_adjustments'] },
  { label: 'II. Item, Barcode & Classification', features: ['item_master_management', 'category_management', 'brand_management', 'uom_management', 'barcode_assignment'] },
  { label: 'III. User, Role & Security', features: ['user_account_management', 'role_assignment', 'rbac_enforcement'] },
  { label: 'IV. Dashboard & UI', features: ['role_dashboards', 'summary_cards', 'quick_actions', 'notification_panel'] },
  { label: 'V. Restocking & Low-Stock', features: ['reorder_monitoring', 'safety_stock', 'consumption_rate', 'low_stock_prediction', 'restock_recommendation', 'restock_quantity', 'restock_approval', 'restock_history'] },
  { label: 'VI. Supplier & Purchase Orders', features: ['supplier_management', 'item_supplier_mapping', 'purchase_order_creation', 'purchase_order_approval', 'po_status_tracking', 'delivery_receiving'] },
  { label: 'VII. Point of Sale', features: ['pos_sales_recording', 'barcode_scanning_sales', 'sales_validation', 'customer_info_capture', 'delivery_receipt_generation', 'pos_transaction_logs'] },
  { label: 'VIII. Transaction Control', features: ['transaction_validation', 'transaction_approval', 'transaction_rejection'] },
  { label: 'IX. Audit & Compliance', features: ['audit_trail_viewing', 'transaction_logs_audit', 'login_logs', 'exception_detection'] },
  { label: 'X. Analytics', features: ['stock_level_analysis', 'consumption_analysis', 'inventory_turnover_analysis'] },
  { label: 'XI. Reporting', features: ['inventory_reports', 'transaction_reports', 'audit_reports', 'export_reports'] },
  { label: 'XII. Document & Data Management', features: ['document_upload', 'document_verification', 'document_viewing', 'data_archiving', 'archive_viewing'] },
];

const prettyFeature = (f) => f.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/**
 * Turn a flat permissions catalog into grouped rows:
 * [{ label, features: [{ feature, title, actions: { create: permId, ... } }] }]
 * Unknown features collect into a trailing "Other" group rather than being dropped.
 */
export function groupByFeatureGroup(permissions) {
  const byFeature = {};
  permissions.forEach((p) => {
    byFeature[p.feature] = byFeature[p.feature] || { feature: p.feature, title: prettyFeature(p.feature), actions: {} };
    byFeature[p.feature].actions[p.action] = p.permission_id;
  });

  const known = new Set();
  const groups = FEATURE_GROUPS.map((g) => {
    const features = g.features.filter((f) => byFeature[f]).map((f) => {
      known.add(f);
      return byFeature[f];
    });
    return { label: g.label, features };
  }).filter((g) => g.features.length > 0);

  const others = Object.values(byFeature).filter((f) => !known.has(f.feature));
  if (others.length > 0) {
    groups.push({ label: 'Other', features: others });
  }
  return groups;
}
```

- [ ] **Step 4: Run the helper test to confirm it passes**

Run from `frontend/`: `npm test -- permissions.test.js --watchAll=false`
Expected: PASS (3 tests).

- [ ] **Step 5: Add styles**

Create `frontend/src/admin/styles/permission_admin.css`:

```css
.perm-admin { padding: 1.5rem; }
.perm-admin h1 { margin-bottom: .25rem; }
.perm-group { margin: 1.25rem 0; }
.perm-group h2 { font-size: .95rem; color: #374151; margin: .5rem 0; }
.perm-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.perm-table th, .perm-table td { border: 1px solid #e5e7eb; padding: .4rem .5rem; text-align: center; }
.perm-table th:first-child, .perm-table td:first-child { text-align: left; }
.perm-cell-toggle { cursor: pointer; }
.perm-cell-na { color: #d1d5db; }
.perm-override-on { background: #ecfdf5; }
.perm-override-off { background: #fef2f2; }
.perm-toolbar { display: flex; gap: .5rem; align-items: center; margin: .75rem 0; }
.perm-dirty { color: #b45309; font-weight: 600; }
```

- [ ] **Step 6: Replace `RolePermissions.jsx` with the editor**

Replace the entire contents of `frontend/src/admin/pages/RolePermissions.jsx`:

```jsx
import React, { useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { groupByFeatureGroup, ROLE_COLUMNS, ACTIONS, keyOf } from '../utils/permissions';
import { toast } from '../utils/toast';
import '../styles/permission_admin.css';

const RolePermissions = () => {
  const [catalog, setCatalog] = useState([]);
  const [grants, setGrants] = useState({}); // `${roleId}:${permId}` => bool
  const [dirty, setDirty] = useState({});   // same key => bool (pending value)
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const [cat, defs] = await Promise.all([
        permissionsAPI.catalog(),
        permissionsAPI.getRoleDefaults(),
      ]);
      setCatalog(cat?.data ?? []);
      const map = {};
      (defs?.data ?? []).forEach((d) => { map[`${d.role_id}:${d.permission_id}`] = !!d.is_granted; });
      setGrants(map);
      setDirty({});
    } catch (e) {
      toast.error(e.message || 'Failed to load permissions');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  const groups = useMemo(() => groupByFeatureGroup(catalog), [catalog]);

  const valueFor = (roleId, permId) => {
    const k = `${roleId}:${permId}`;
    return k in dirty ? dirty[k] : !!grants[k];
  };

  const toggle = (roleId, permId) => {
    const k = `${roleId}:${permId}`;
    const next = !valueFor(roleId, permId);
    setDirty((d) => {
      const copy = { ...d };
      if (next === !!grants[k]) delete copy[k]; else copy[k] = next;
      return copy;
    });
  };

  const save = async () => {
    const changes = Object.entries(dirty).map(([k, v]) => {
      const [role_id, permission_id] = k.split(':').map(Number);
      return { role_id, permission_id, is_granted: v };
    });
    if (changes.length === 0) { toast.info('No changes to save'); return; }
    setSaving(true);
    try {
      await permissionsAPI.updateRoleDefaults(changes);
      toast.success(`Saved ${changes.length} change(s)`);
      await load();
    } catch (e) {
      toast.error(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const dirtyCount = Object.keys(dirty).length;

  return (
    <AdminLayout>
      <div className="perm-admin">
        <h1>Role Permissions</h1>
        <p>Default access per role. User-specific exceptions are managed in User Overrides.</p>

        <div className="perm-toolbar">
          <button onClick={save} disabled={saving || dirtyCount === 0}>
            {saving ? 'Saving…' : 'Save changes'}
          </button>
          {dirtyCount > 0 && <span className="perm-dirty">{dirtyCount} unsaved</span>}
        </div>

        {loading ? <div>Loading…</div> : groups.map((g) => (
          <div className="perm-group" key={g.label}>
            <h2>{g.label}</h2>
            <table className="perm-table">
              <thead>
                <tr>
                  <th>Feature</th>
                  {ACTIONS.map((a) => ROLE_COLUMNS.map((r) => (
                    <th key={`${a}-${r.id}`}>{a[0].toUpperCase()}·{r.label}</th>
                  )))}
                </tr>
              </thead>
              <tbody>
                {g.features.map((f) => (
                  <tr key={f.feature}>
                    <td>{f.title}</td>
                    {ACTIONS.map((a) => ROLE_COLUMNS.map((r) => {
                      const permId = f.actions[a];
                      if (!permId) return <td key={`${f.feature}-${a}-${r.id}`} className="perm-cell-na">—</td>;
                      return (
                        <td key={`${f.feature}-${a}-${r.id}`} className="perm-cell-toggle"
                            onClick={() => toggle(r.id, permId)}>
                          <input type="checkbox" readOnly checked={valueFor(r.id, permId)} />
                        </td>
                      );
                    }))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    </AdminLayout>
  );
};

export default RolePermissions;
```

- [ ] **Step 7: Manually verify in the browser**

Log in as an admin, open `/admin/roles`. Confirm: the grid renders all 12 groups, toggling a cell shows the unsaved counter, "Save changes" persists (reload shows the new value). Toggle `inventory_transfers · approve` for BM, save, reload — it stays.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/utils/permissions.js frontend/src/admin/utils/permissions.test.js frontend/src/admin/styles/permission_admin.css frontend/src/admin/pages/RolePermissions.jsx
git commit -m "feat(rbac): role permissions editor (Page A) + shared grouping helper"
```

---

## Task 10: Page B — User Permission Overrides

**Files:**
- Create: `frontend/src/admin/pages/UserPermissionOverrides.jsx`
- Modify: `frontend/src/App.jsx`, `frontend/src/admin/utils/roles.js`, `frontend/src/admin/components/Sidebar.jsx`

- [ ] **Step 1: Create the page**

Create `frontend/src/admin/pages/UserPermissionOverrides.jsx`:

```jsx
import React, { useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI, batchAPI } from '../services/api';
import { groupByFeatureGroup, ACTIONS } from '../utils/permissions';
import { toast } from '../utils/toast';
import '../styles/permission_admin.css';

const UserPermissionOverrides = () => {
  const [users, setUsers] = useState([]);
  const [catalog, setCatalog] = useState([]);
  const [userId, setUserId] = useState('');
  const [effective, setEffective] = useState({}); // 'feature.action' => bool (role default ∪ override)
  const [overrides, setOverrides] = useState({}); // permId => bool (active override)
  const [pending, setPending] = useState({});     // permId => bool|null (null = clear)
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    (async () => {
      const [cat, batch] = await Promise.all([
        permissionsAPI.catalog(),
        batchAPI.get({ include: ['users'] }),
      ]);
      setCatalog(cat?.data ?? []);
      setUsers(batch?.data?.users?.data ?? []);
    })().catch((e) => toast.error(e.message));
  }, []);

  const loadUser = async (id) => {
    setUserId(id);
    setPending({});
    setReason('');
    if (!id) { setEffective({}); setOverrides({}); return; }
    try {
      const res = await permissionsAPI.getUserPermissions(id);
      setEffective(res?.data?.effective ?? {});
      const ov = {};
      (res?.data?.overrides ?? []).forEach((o) => { ov[o.permission_id] = !!o.is_granted; });
      setOverrides(ov);
    } catch (e) { toast.error(e.message); }
  };

  const groups = useMemo(() => groupByFeatureGroup(catalog), [catalog]);
  const permFeatureAction = useMemo(() => {
    const m = {};
    catalog.forEach((p) => { m[p.permission_id] = `${p.feature}.${p.action}`; });
    return m;
  }, [catalog]);

  const isOverridden = (permId) => permId in pending ? pending[permId] !== null : permId in overrides;
  const effectiveValue = (permId) => {
    if (permId in pending) {
      if (pending[permId] !== null) return pending[permId];
    } else if (permId in overrides) {
      return overrides[permId];
    }
    return !!effective[permFeatureAction[permId]];
  };

  // Cycle: default → granted-override → denied-override → default
  const cycle = (permId) => {
    setPending((p) => {
      const copy = { ...p };
      const current = permId in copy ? copy[permId] : (permId in overrides ? overrides[permId] : 'default');
      let next;
      if (current === 'default' || current === null) next = true;
      else if (current === true) next = false;
      else next = null; // clear back to default
      copy[permId] = next;
      return copy;
    });
  };

  const save = async () => {
    const changes = Object.entries(pending).map(([permission_id, v]) => ({
      permission_id: Number(permission_id),
      is_granted: v, // true | false | null(clear)
    }));
    if (changes.length === 0) { toast.info('No changes'); return; }
    if (!reason.trim()) { toast.error('A reason is required'); return; }
    setSaving(true);
    try {
      await permissionsAPI.updateUserPermissions(userId, reason.trim(), changes);
      toast.success('Overrides saved');
      await loadUser(userId);
    } catch (e) {
      toast.error(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  return (
    <AdminLayout>
      <div className="perm-admin">
        <h1>User Permission Overrides</h1>
        <p>Per-user exceptions to role defaults. An override always wins over the role default.</p>

        <div className="perm-toolbar">
          <select value={userId} onChange={(e) => loadUser(e.target.value)}>
            <option value="">Select a user…</option>
            {users.map((u) => (
              <option key={u.user_id} value={u.user_id}>
                {u.first_name} {u.last_name} ({u.role?.role_name})
              </option>
            ))}
          </select>
          <input placeholder="Reason (required to save)" value={reason}
                 onChange={(e) => setReason(e.target.value)} style={{ flex: 1 }} />
          <button onClick={save} disabled={saving || !userId}>Save overrides</button>
        </div>

        {userId && groups.map((g) => (
          <div className="perm-group" key={g.label}>
            <h2>{g.label}</h2>
            <table className="perm-table">
              <thead><tr><th>Feature</th>{ACTIONS.map((a) => <th key={a}>{a}</th>)}</tr></thead>
              <tbody>
                {g.features.map((f) => (
                  <tr key={f.feature}>
                    <td>{f.title}</td>
                    {ACTIONS.map((a) => {
                      const permId = f.actions[a];
                      if (!permId) return <td key={a} className="perm-cell-na">—</td>;
                      const val = effectiveValue(permId);
                      const cls = isOverridden(permId) ? (val ? 'perm-override-on' : 'perm-override-off') : '';
                      return (
                        <td key={a} className={`perm-cell-toggle ${cls}`} title="Click to cycle: default → grant → deny → default"
                            onClick={() => cycle(permId)}>
                          {val ? '✓' : '✗'}{isOverridden(permId) ? '*' : ''}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    </AdminLayout>
  );
};

export default UserPermissionOverrides;
```

- [ ] **Step 2: Register the route in `App.jsx`**

In `frontend/src/App.jsx`, add a lazy import alongside the others:
```jsx
const UserPermissionOverrides = lazy(() => import('./admin/pages/UserPermissionOverrides'));
```
And add a route near the `/admin/roles` route (line ~112):
```jsx
          <Route path="/admin/user-permissions" element={<RoleRoute><UserPermissionOverrides /></RoleRoute>} />
```

- [ ] **Step 3: Add route access (admin-only) in `roles.js`**

In `frontend/src/admin/utils/roles.js`, add to the `ROUTE_ACCESS` map (in the Admin/security block):
```javascript
  '/admin/user-permissions': [ROLES.ADMIN],
  '/admin/permission-audit': [ROLES.ADMIN],
```

- [ ] **Step 4: Add a sidebar/menu entry**

In `frontend/src/admin/components/Sidebar.jsx`, find where the existing `/admin/roles` ("Role & Permissions") item is rendered and add two sibling items in the same admin section:
```jsx
{ name: 'User Overrides', path: '/admin/user-permissions', icon: 'role' },
{ name: 'Permission Audit', path: '/admin/permission-audit', icon: 'audit' },
```
(Match the exact item-object shape used by the surrounding entries in that file.)

- [ ] **Step 5: Manually verify**

As admin, open `/admin/user-permissions`. Pick a BM user. Click `inventory_transfers · approve` to cycle to grant (`✓*`, green), enter a reason, Save. Reload the user — the override persists and the cell stays starred. Click again through deny→default and save to confirm clearing works.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/pages/UserPermissionOverrides.jsx frontend/src/App.jsx frontend/src/admin/utils/roles.js frontend/src/admin/components/Sidebar.jsx
git commit -m "feat(rbac): user permission overrides page (Page B) + route + menu"
```

---

## Task 11: Page C — Permission Audit Log Viewer

**Files:**
- Create: `frontend/src/admin/pages/PermissionAuditLog.jsx`
- Modify: `frontend/src/App.jsx`

- [ ] **Step 1: Create the page**

Create `frontend/src/admin/pages/PermissionAuditLog.jsx`:

```jsx
import React, { useEffect, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import { permissionsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/permission_admin.css';

const PermissionAuditLog = () => {
  const [rows, setRows] = useState([]);
  const [filters, setFilters] = useState({ change_type: '', feature: '', action: '', date_from: '', date_to: '' });
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    try {
      const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v));
      const res = await permissionsAPI.getAuditLogs(clean);
      // controller returns paginator under data; support both shapes
      setRows(res?.data?.data ?? res?.data ?? []);
    } catch (e) {
      toast.error(e.message || 'Failed to load audit log');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

  const set = (k) => (e) => setFilters((f) => ({ ...f, [k]: e.target.value }));

  return (
    <AdminLayout>
      <div className="perm-admin">
        <h1>Permission Audit Log</h1>

        <div className="perm-toolbar">
          <select value={filters.change_type} onChange={set('change_type')}>
            <option value="">All types</option>
            <option value="role_default">Role default</option>
            <option value="user_override">User override</option>
          </select>
          <input placeholder="feature" value={filters.feature} onChange={set('feature')} />
          <input placeholder="action" value={filters.action} onChange={set('action')} />
          <input type="date" value={filters.date_from} onChange={set('date_from')} />
          <input type="date" value={filters.date_to} onChange={set('date_to')} />
          <button onClick={load} disabled={loading}>Apply</button>
        </div>

        <table className="perm-table">
          <thead>
            <tr>
              <th>When</th><th>Changed by</th><th>Type</th><th>Target</th>
              <th>Feature</th><th>Action</th><th>Old→New</th><th>Reason</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.log_id}>
                <td>{new Date(r.changed_at).toLocaleString()}</td>
                <td>{r.changed_by?.first_name ? `${r.changed_by.first_name} ${r.changed_by.last_name}` : r.changed_by}</td>
                <td>{r.change_type}</td>
                <td>{r.target_user_id
                  ? (r.target_user ? `${r.target_user.first_name} ${r.target_user.last_name}` : `User #${r.target_user_id}`)
                  : `Role #${r.target_role_id}`}</td>
                <td>{r.permission?.feature}</td>
                <td>{r.permission?.action}</td>
                <td>{String(!!r.old_value)} → {String(!!r.new_value)}</td>
                <td>{r.reason}</td>
              </tr>
            ))}
            {!loading && rows.length === 0 && (
              <tr><td colSpan="8" style={{ textAlign: 'center' }}>No entries</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </AdminLayout>
  );
};

export default PermissionAuditLog;
```

- [ ] **Step 2: Register the route in `App.jsx`**

Add the lazy import:
```jsx
const PermissionAuditLog = lazy(() => import('./admin/pages/PermissionAuditLog'));
```
And the route (next to `/admin/user-permissions`):
```jsx
          <Route path="/admin/permission-audit" element={<RoleRoute><PermissionAuditLog /></RoleRoute>} />
```
(Route access was already added to `roles.js` in Task 10 Step 3, and the menu item in Task 10 Step 4.)

- [ ] **Step 3: Manually verify**

As admin, make a change on Page A or B, then open `/admin/permission-audit`. The new entry appears at the top with correct old→new, target, feature/action, and reason. Filtering by `change_type=user_override` narrows the list.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/PermissionAuditLog.jsx frontend/src/App.jsx
git commit -m "feat(rbac): permission audit log viewer (Page C)"
```

---

## Final verification

- [ ] **Backend suite green:** from `back-end/`, run `php artisan test tests/Feature/Permissions` → all tests pass.
- [ ] **No regression in existing RBAC behavior:** the 74 `role:` routes are untouched; run `php artisan test` to confirm the broader suite still passes.
- [ ] **Frontend helper test green:** from `frontend/`, `npm test -- permissions.test.js --watchAll=false`.
- [ ] **Manual smoke:** admin can edit role defaults (Page A), set/clear a user override with a required reason (Page B), and see both kinds of change in the audit viewer (Page C). A non-admin hitting `/api/role-permissions` gets 403.

---

## Notes for Phase 2 (out of scope here)

Migrating the 74 `role:` routes to `permission:feature,action` (spec §6) is a separate plan. When it happens, `pos_sales_recording.create` enforcement will change from the current `admin,branch_manager,inventory_analyst` to the seeded `admin,branch_manager,warehouse_personnel` — flag this in that plan's testing notes. The frontend `roles.js` `ROUTE_ACCESS` map can also be migrated to consume `effectivePermissionsFor` via a bootstrap endpoint at that time.
```
