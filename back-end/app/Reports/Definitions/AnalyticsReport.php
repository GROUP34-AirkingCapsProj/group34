<?php

namespace App\Reports\Definitions;

use App\Models\User;
use App\Reports\ReportCharts;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Support\PermissionResolver;

/** Generate the complete dataset behind an analytics view, including its charts. */
class AnalyticsReport implements ReportDefinition
{
    public const VIEWS = ['stock-level', 'movement', 'predictions', 'profit-loss', 'branch-performance'];

    public function __construct(private string $view, private DashboardAnalyticsService $analytics)
    {
        if (!in_array($view, self::VIEWS, true)) throw new \InvalidArgumentException('Unknown analytics report view.');
    }

    public function key(): string { return 'analytics-' . $this->view; }

    public function title(): string
    {
        return match ($this->view) {
            'stock-level' => 'Stock Level Analysis Report',
            'movement' => 'Movement and Turnover Report',
            'predictions' => 'Restocking Forecast Report',
            'profit-loss' => 'Confirmed Stock Loss Report',
            'branch-performance' => 'Location Reorder Compliance Report',
        };
    }

    public function description(): string { return 'Matching analytics graphs, summaries, and complete supporting records for the selected location and category.'; }
    public function formats(): array { return ['pdf', 'csv']; }
    public function pdfRowCap(): int { return 5000; }
    public function parameters(): array
    {
        return [new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('category_id', ReportParameter::TYPE_CATEGORY, 'Category')];
    }

    public function availableTo(User $user): bool
    {
        return app(PermissionResolver::class)->canUser((int) $user->user_id, 'analytics_dashboard', 'read');
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $location = $params->int('location_id');
        if (!$user->readsAllLocations()) {
            $own = $user->scopedLocationId();
            $location = $own === null || ($location !== null && $location !== $own) ? -1 : $own;
        }
        $category = $params->int('category_id');
        $data = match ($this->view) {
            'stock-level' => $this->analytics->stockLevel($location, $category),
            'movement' => $this->analytics->movement($location, $category),
            'predictions' => $this->analytics->predictions($location, $category),
            'profit-loss' => $this->analytics->profitLoss($location, $category),
            'branch-performance' => $this->analytics->locationPerformance($location, $category),
        };
        [$rows, $columns, $money, $charts, $notes, $summary] = match ($this->view) {
            'stock-level' => [
                $data['stockRecords'],
                ['product' => 'Product', 'product_code' => 'Code', 'category' => 'Category', 'location' => 'Location',
                    'quantity_on_hand' => 'On Hand', 'threshold' => 'Reorder Point', 'status' => 'Status', 'stock_value' => 'Value at Cost'],
                ['stock_value'], [], ['Live stock snapshot valued at cost per piece. Critical means out of stock; low stock means at or below the reorder point.'],
                ['Stock lines' => count($data['stockRecords']), 'Total units' => $data['statsData']['totalUnits'], 'Stock value at cost (PHP)' => $data['statsData']['totalStockValue']]],
            'movement' => [
                $data['movementRecords'],
                ['name' => 'Product', 'category' => 'Category', 'onHand' => 'On Hand', 'unitsOut' => 'Units Consumed',
                    'week1' => 'Oldest 9 Days', 'week2' => 'Week 2', 'week3' => 'Week 3', 'week4' => 'Latest 7 Days', 'turnover' => 'Turnover'],
                [], [ReportCharts::bar('Stock movement trends', array_map(fn ($label, $value) => ['label' => $label, 'value' => $value], $data['movementPeriod']['labels'], $data['weeklyMovement']))],
                ['Consumption period: ' . $data['movementPeriod']['from'] . ' to ' . $data['movementPeriod']['to'] . '. All 30 days are included; the oldest bucket covers 9 days.',
                    'Records group products across the selected locations. Turnover is consumption divided by current stock; a zero-stock ratio is unavailable.'],
                ['Units consumed' => array_sum(array_column($data['movementRecords'], 'unitsOut')), 'Average turnover' => $data['movementStats']['avgTurnoverRate']]],
            'predictions' => [
                $data['restockRecommendations'],
                ['name' => 'Product', 'branch' => 'Warehouse', 'priority' => 'Priority', 'currentStock' => 'Stock at Calculation',
                    'suggestedQty' => 'Suggested Units', 'estCost' => 'Estimated Cost', 'daysOfCover' => 'Days of Cover', 'computedAt' => 'Calculated At'],
                ['estCost'], [ReportCharts::bar('Restocking priorities', ReportCharts::grouped($data['restockRecommendations'], 'priority', null), 'stock lines'),
                    ReportCharts::bar('Estimated restock cost by warehouse', ReportCharts::grouped($data['restockRecommendations'], 'branch', 'estCost'), 'PHP'),
                    ReportCharts::bar('Projected days of cover: stockouts within 30 days', array_map(fn ($row) => [
                        'label' => $row['name'] . ' - ' . $row['branch'], 'value' => $row['daysOfCover'],
                    ], array_values(array_filter($data['restockRecommendations'], fn ($row) => $row['stockoutRisk'] === 'Yes'))), 'days')],
                ['Restocking estimates are saved forecasts, not confirmed purchases or guaranteed stockout dates. Each record shows its calculation time.'],
                ['Restock lines' => $data['predictionsStats']['restockNeeded'], 'Predicted stockouts within 30 days' => $data['predictionsStats']['predictedStockouts'], 'Estimated restock cost (PHP)' => $data['predictionsStats']['estRestockCost']]],
            'profit-loss' => [
                $data['lossRecords'],
                ['date' => 'Report Date', 'itemName' => 'Product', 'branchName' => 'Location', 'itemCategory' => 'Category',
                    'lossType' => 'Type', 'qty' => 'Units Lost', 'lossValue' => 'Loss at Cost', 'reason' => 'Reason'],
                ['lossValue'], [ReportCharts::bar('Confirmed loss by location', ReportCharts::grouped($data['lossRecords'], 'branchName', 'lossValue'), 'PHP'),
                    ReportCharts::bar('Confirmed loss by category', ReportCharts::grouped($data['lossRecords'], 'itemCategory', 'lossValue'), 'PHP')],
                ['Confirmed stock deductions only, valued at current cost per piece. Open incident reports are excluded from the graphs and record totals.'],
                ['Confirmed loss (PHP)' => $data['profitLossStats']['totalProfitLoss'], 'Pending incident reports (excluded)' => $data['profitLossStats']['pendingReports']]],
            'branch-performance' => [
                $data['branchPerformanceData'],
                ['name' => 'Location', 'items' => 'Stock Lines', 'totalValue' => 'Stock Value at Cost', 'lowStockCount' => 'Low / Critical',
                    'restockNeeded' => 'Restock Lines', 'restockCost' => 'Restock Cost', 'avgTurnover' => 'Turnover', 'efficiencyScore' => 'Reorder Compliance %'],
                ['totalValue', 'restockCost'], [ReportCharts::bar('Location reorder compliance', array_map(fn ($r) => ['label' => $r['name'], 'value' => $r['efficiencyScore']], $data['branchPerformanceData']), '%')],
                ['Reorder compliance is the percentage of stock lines above their reorder point. Stock values are live snapshots at cost per piece. Turnover uses the last 30 days.'],
                ['Locations' => count($data['branchPerformanceData']), 'Stock value at cost (PHP)' => array_sum(array_column($data['branchPerformanceData'], 'totalValue'))]],
        };
        return new ReportDataset(columns: $columns, rows: $rows, summary: array_map(fn ($value) => (string) $value, $summary),
            notes: $notes, columnFormats: array_fill_keys($money, ReportDataset::FORMAT_MONEY), charts: $charts);
    }
}
