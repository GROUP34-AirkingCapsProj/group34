<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Definitions\InventorySummaryReport;
use App\Reports\Definitions\AnalyticsReport;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Reports\Definitions\LocationPerformanceReport;
use App\Reports\Definitions\LowStockReport;
use App\Reports\Definitions\TransactionReport;
use App\Reports\Definitions\UserActivityReport;
use App\Reports\Definitions\WarehouseUtilizationReport;

/**
 * The one place that knows which reports exist.
 *
 * Adding a report is a class plus a line here — no controller, route, or
 * frontend change, because the page renders whatever the catalog returns.
 */
class ReportRegistry
{
    private const DEFINITIONS = [
        InventorySummaryReport::class,
        TransactionReport::class,
        LocationPerformanceReport::class,
        LowStockReport::class,
        WarehouseUtilizationReport::class,
        UserActivityReport::class,
    ];

    /** @var array<string,ReportDefinition>|null */
    private ?array $resolved = null;

    /** @return array<string,ReportDefinition> key => definition */
    public function all(): array
    {
        if ($this->resolved === null) {
            $this->resolved = [];
            foreach (self::DEFINITIONS as $class) {
                $definition = app($class);
                $this->resolved[$definition->key()] = $definition;
            }
            foreach (AnalyticsReport::VIEWS as $view) {
                $definition = new AnalyticsReport($view, app(DashboardAnalyticsService::class));
                $this->resolved[$definition->key()] = $definition;
            }
        }

        return $this->resolved;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?ReportDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return ReportDefinition[] the reports this user may actually run, list-indexed */
    public function availableTo(User $user): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ReportDefinition $d): bool => $d->availableTo($user)
        ));
    }
}
