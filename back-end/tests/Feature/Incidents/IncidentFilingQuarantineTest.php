<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncidentFilingQuarantineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_filing_a_defective_report_quarantines_its_items(): void
    {
        $incident = $this->fileWithItems('defective');

        $this->assertSame(
            IncidentItem::STATUS_QUARANTINED,
            IncidentItem::where('incident_id', $incident->incident_id)->firstOrFail()->status
        );
        $this->assertSame(IncidentReport::statusId('Open'), (int) $incident->status_id);
    }

    /** Zone mismatch moves no stock, so it creates no items and quarantines nothing. */
    public function test_a_zone_mismatch_creates_no_items(): void
    {
        $incident = $this->fileWithItems('zone_mismatch');

        $this->assertSame(0, IncidentItem::where('incident_id', $incident->incident_id)->count());
    }

    public function test_an_other_report_creates_no_items(): void
    {
        $incident = $this->fileWithItems('other');

        $this->assertSame(0, IncidentItem::where('incident_id', $incident->incident_id)->count());
    }

    private function fileWithItems(string $reason): IncidentReport
    {
        $location = \App\Models\Location::query()->firstOrFail();
        $product  = \App\Models\Product::query()->firstOrFail();
        $userId   = (int) \App\Models\User::query()->firstOrFail()->getKey();

        $data = [
            'source_type' => 'standalone',
            'location_id' => $location->location_id,
            'reason'      => $reason,
            'narrative'   => 'test',
        ];

        if (! in_array($reason, ['zone_mismatch', 'other'], true)) {
            $data['product_id'] = $product->product_id;
            $data['quantity']   = 5;
            $data['items']      = [['product_id' => $product->product_id, 'quantity' => 5]];
        } else {
            $data['product_id'] = $product->product_id;
            $data['quantity']   = 1;
        }

        return app(IncidentService::class)->file($data, $userId);
    }
}
