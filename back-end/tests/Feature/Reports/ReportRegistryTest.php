<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportRegistry;

class ReportRegistryTest extends ReportTestCase
{
    public function test_it_resolves_a_definition_by_key(): void
    {
        $registry = app(ReportRegistry::class);

        $this->assertTrue($registry->has('inventory-summary'));
        $this->assertSame('inventory-summary', $registry->get('inventory-summary')->key());
    }

    public function test_an_unknown_key_is_not_found(): void
    {
        $this->assertFalse(app(ReportRegistry::class)->has('does-not-exist'));
        $this->assertNull(app(ReportRegistry::class)->get('does-not-exist'));
    }

    public function test_the_catalog_hides_reports_a_user_cannot_run(): void
    {
        $scoped = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $keys = collect(app(ReportRegistry::class)->availableTo($scoped))
            ->map(fn ($d) => $d->key())->all();

        $this->assertNotContains('user-activity', $keys,
            'User Activity has no location column, so a scoped user must not be offered it.');
        $this->assertContains('inventory-summary', $keys);
    }
}
