<?php

namespace Tests\Feature\Ops;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * /api/scheduler/tick is the one route that triggers server work without a user
 * behind it, so its guard is the whole feature. These tests exist to keep it
 * failing closed.
 *
 * Artisan is mocked throughout: a real schedule:run would fire backup:run, which
 * is scheduled everyMinute and shells out to mysqldump. The subject here is the
 * gate, not Laravel's scheduler.
 */
class SchedulerTickTest extends TestCase
{
    private const TOKEN = 'a-test-token-of-at-least-32-characters-long';

    protected function setUp(): void
    {
        parent::setUp();

        // The route is rate limited to 6/min and several tests call it more than
        // that between them; the limiter is asserted in its own test below.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function expectNoScheduleRun(): void
    {
        Artisan::shouldReceive('call')->never();
        Artisan::shouldReceive('output')->never();
    }

    private function expectScheduleRun(string $output = ''): void
    {
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn($output);
    }

    public function test_it_404s_when_no_token_is_configured(): void
    {
        config(['scheduler.run_token' => '']);
        $this->expectNoScheduleRun();

        // 404 rather than 503: an unconfigured deployment should look like it
        // has no such endpoint, which is the default for every install that does
        // not use an external cron.
        $this->getJson('/api/scheduler/tick')->assertNotFound();
    }

    public function test_it_404s_when_the_configured_token_is_too_short(): void
    {
        // The floor is what stops a placeholder like "changeme" arming a route
        // that runs commands.
        config(['scheduler.run_token' => 'short']);
        $this->expectNoScheduleRun();

        $this->getJson('/api/scheduler/tick?token=short')->assertNotFound();
    }

    public function test_it_404s_on_a_wrong_token_rather_than_403(): void
    {
        config(['scheduler.run_token' => self::TOKEN]);
        $this->expectNoScheduleRun();

        // A 403 would confirm the endpoint exists and invite guessing.
        $this->withHeader('X-Scheduler-Token', 'not-the-token-but-long-enough-to-pass-length')
            ->getJson('/api/scheduler/tick')
            ->assertNotFound();
    }

    public function test_it_404s_when_no_token_is_presented(): void
    {
        config(['scheduler.run_token' => self::TOKEN]);
        $this->expectNoScheduleRun();

        $this->getJson('/api/scheduler/tick')->assertNotFound();
    }

    public function test_it_runs_the_scheduler_with_a_valid_header_token(): void
    {
        config(['scheduler.run_token' => self::TOKEN]);
        $this->expectScheduleRun();

        $this->withHeader('X-Scheduler-Token', self::TOKEN)
            ->postJson('/api/scheduler/tick')
            ->assertOk()
            ->assertJson(['ran' => true, 'exit_code' => 0]);
    }

    public function test_it_accepts_the_query_string_fallback(): void
    {
        // Supported because not every free cron service can set headers. The
        // docs steer callers to the header; this keeps the fallback honest.
        config(['scheduler.run_token' => self::TOKEN]);
        $this->expectScheduleRun();

        $this->getJson('/api/scheduler/tick?token=' . self::TOKEN)->assertOk();
    }

    public function test_the_header_wins_over_the_query_string(): void
    {
        config(['scheduler.run_token' => self::TOKEN]);
        $this->expectScheduleRun();

        $this->withHeader('X-Scheduler-Token', self::TOKEN)
            ->getJson('/api/scheduler/tick?token=wrong')
            ->assertOk();
    }

    public function test_it_does_not_leak_which_commands_ran(): void
    {
        config(['scheduler.run_token' => self::TOKEN]);
        $this->expectScheduleRun("Running ['artisan' backup:run]\nRunning ['artisan' analytics:restock]");

        $response = $this->withHeader('X-Scheduler-Token', self::TOKEN)
            ->getJson('/api/scheduler/tick')
            ->assertOk();

        // A count is enough for a cron dashboard; the command names describe the
        // system to a caller that proved only that it holds a shared secret.
        $response->assertJsonPath('task_lines', 2);
        $this->assertStringNotContainsString('backup:run', $response->getContent());
        $this->assertStringNotContainsString('analytics:restock', $response->getContent());
    }

    public function test_it_rate_limits_to_six_a_minute(): void
    {
        // The only test that keeps ThrottleRequests on. The limiter is what makes
        // a leaked-token worst case "the scheduler ticks a bit more often" rather
        // than "an attacker brute-forces the token at request speed".
        $this->withMiddleware(ThrottleRequests::class);

        config(['scheduler.run_token' => self::TOKEN]);
        Artisan::shouldReceive('call')->times(6)->with('schedule:run')->andReturn(0);
        Artisan::shouldReceive('output')->times(6)->andReturn('');

        for ($i = 0; $i < 6; $i++) {
            $this->withHeader('X-Scheduler-Token', self::TOKEN)
                ->getJson('/api/scheduler/tick')
                ->assertOk();
        }

        $this->withHeader('X-Scheduler-Token', self::TOKEN)
            ->getJson('/api/scheduler/tick')
            ->assertStatus(429);
    }

    public function test_the_response_never_echoes_the_token(): void
    {
        config(['scheduler.run_token' => self::TOKEN]);
        $this->expectScheduleRun();

        $content = $this->withHeader('X-Scheduler-Token', self::TOKEN)
            ->getJson('/api/scheduler/tick')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::TOKEN, $content);
    }
}
