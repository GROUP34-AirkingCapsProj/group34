<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * One-off / ops command to create (or promote) an admin login. There is no
 * public registration route (see NoPublicRegistrationTest) and AdminUserSeeder
 * only ever creates its own fixed demo account, so this is the supported way
 * to provision a real admin on a fresh environment such as a Render deploy.
 *
 * Password is always prompted, never a CLI argument, so it never lands in
 * shell history, the process list, or Render's build/run logs.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create {email} {--first-name=Admin} {--last-name=User} {--password=}';

    protected $description = 'Create (or promote to admin) a user with the given email.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $validator = Validator::make(['email' => $email], ['email' => 'required|email']);
        if ($validator->fails()) {
            $this->error('Invalid email address.');
            return Command::FAILURE;
        }

        $password = $this->option('password');

        if ($password === null) {
            $password = $this->secret('Password (min 8 characters)');
            $confirm = $this->secret('Confirm password');

            if ($password !== $confirm) {
                $this->error('Passwords did not match.');
                return Command::FAILURE;
            }
        }

        if (strlen((string) $password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return Command::FAILURE;
        }

        $adminRole = Role::where('role_name', 'admin')->first();
        $activeStatus = StatusLookup::where('status_category', 'user')->where('status_name', 'Active')->first();

        if (!$adminRole || !$activeStatus) {
            $this->error('Roles/statuses are not seeded yet. Run the base seeders first (php artisan db:seed).');
            return Command::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->role_id = $adminRole->role_id;
            $user->password_hash = Hash::make($password);
            $user->status_id = $activeStatus->status_id;
            $user->save();
            $this->info("Existing user {$email} updated and promoted to admin.");
            return Command::SUCCESS;
        }

        User::create([
            'first_name' => $this->option('first-name'),
            'last_name' => $this->option('last-name'),
            'username' => $email,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'role_id' => $adminRole->role_id,
            'status_id' => $activeStatus->status_id,
        ]);

        $this->info("Admin user {$email} created.");

        return Command::SUCCESS;
    }
}
