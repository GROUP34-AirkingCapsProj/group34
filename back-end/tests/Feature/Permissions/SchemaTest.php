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
