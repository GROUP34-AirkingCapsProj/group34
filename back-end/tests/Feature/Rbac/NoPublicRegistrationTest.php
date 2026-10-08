<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Accounts are created only through POST /api/users, gated on
 * permission:user_account_management,create.
 *
 * There used to be an unauthenticated POST /api/register that took `role_id`
 * straight from the request body, set the account active, and returned a
 * working `['*']` Sanctum token — so anyone who could reach the API could mint
 * themselves an admin. Nothing in the frontend ever called it. These tests pin
 * that it stays gone: re-adding a public signup route is the kind of change
 * that looks harmless in a diff.
 */
class NoPublicRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_register_endpoint_does_not_exist(): void
    {
        $before = User::withTrashed()->count();

        $this->postJson('/api/register', [
            'username'              => 'attacker',
            'email'                 => 'attacker@example.test',
            'password'              => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'first_name'            => 'At',
            'last_name'             => 'Tacker',
            'role_id'               => 1, // admin
        ])->assertNotFound();

        $this->assertSame($before, User::withTrashed()->count());
    }

    public function test_no_route_creates_a_user_without_authentication(): void
    {
        $unauthenticated = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('POST', $route->methods(), true))
            ->reject(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        // Login, OTP, and password reset are deliberately public. Account
        // creation must not join them.
        $this->assertNotContains('api/register', $unauthenticated);
        $this->assertNotContains('api/users', $unauthenticated);
    }
}
