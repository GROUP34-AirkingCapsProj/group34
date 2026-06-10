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

    public function test_role_id_columns_are_bigint(): void
    {
        $rpType = \Illuminate\Support\Facades\DB::selectOne('SHOW COLUMNS FROM role_permissions WHERE Field = ?', ['role_id']);
        $palType = \Illuminate\Support\Facades\DB::selectOne('SHOW COLUMNS FROM permission_audit_logs WHERE Field = ?', ['target_role_id']);

        $this->assertStringContainsString('bigint', strtolower($rpType->Type), 'role_permissions.role_id should be bigint');
        $this->assertStringContainsString('bigint', strtolower($palType->Type), 'permission_audit_logs.target_role_id should be bigint');
    }
}
