<?php

namespace App\Reports;

/** Charts use the report's raw rows, before any PDF formatting. No separate queries. */
class ReportCharts
{
    public static function bar(string $title, array $bars, string $unit = 'units'): array
    {
        $bars = array_map(fn ($bar) => ['label' => (string) $bar['label'], 'value' => round((float) $bar['value'], 2)], $bars);
        $note = 'Values come from the supporting records in this report.';
        if (count($bars) > 12 && !in_array($unit, ['%', 'days'], true)) {
            usort($bars, fn ($a, $b) => abs($b['value']) <=> abs($a['value']));
            $other = array_slice($bars, 11);
            $bars = array_slice($bars, 0, 11);
            $bars[] = ['label' => 'Other (' . count($other) . ' groups)', 'value' => round(array_sum(array_column($other, 'value')), 2)];
            $note .= ' The largest 11 groups are shown individually; remaining groups are combined as Other.';
        }
        if (collect($bars)->contains(fn ($bar) => $bar['value'] < 0)) {
            $note .= ' Red bars represent negative values; bar length shows magnitude.';
        }
        return ['title' => $title, 'unit' => $unit, 'bars' => $bars, 'note' => $note];
    }

    public static function grouped(array $rows, string $group, ?string $measure): array
    {
        return collect($rows)->groupBy(fn ($row) => $row[$group] ?? 'Unspecified')->map(fn ($items, $label) => [
            'label' => $label, 'value' => $measure === null ? $items->count() : $items->sum($measure),
        ])->values()->all();
    }

    public function forDataset(ReportDataset $dataset): array
    {
        if ($dataset->charts !== [] || $dataset->rows === []) return $dataset->charts;
        $columns = $dataset->columns;
        $rows = $dataset->rows;
        if (isset($columns['stock_value'])) {
            $charts = [self::bar('Stock value at cost by location', self::grouped($rows, 'location', 'stock_value'), 'PHP')];
            if (isset($rows[0]['status'])) {
                $charts[] = self::bar('Stock status distribution', self::grouped($rows, 'status', null), 'stock lines');
            }
            return $charts;
        }
        if (isset($columns['shortfall'])) {
            return [self::bar('Restock shortfall by location', self::grouped($rows, 'location', 'shortfall')),
                self::bar('Low and critical stock lines by category', self::grouped($rows, 'category', null), 'stock lines')];
        }
        if (isset($columns['closing_valuation'])) {
            return [self::bar('Current stock value at cost by location', self::grouped($rows, 'location', 'closing_valuation'), 'PHP'),
                self::bar('Units received by location', self::grouped($rows, 'location', 'units_in')),
                self::bar('Units dispatched by location', self::grouped($rows, 'location', 'units_out'))];
        }
        if (isset($columns['occupied_units'])) {
            return [self::bar('Occupied stock by warehouse', self::grouped($rows, 'location', 'occupied_units')),
                self::bar('Goods dispatched by warehouse', self::grouped($rows, 'location', 'goods_out_total'))];
        }
        if (isset($columns['outcome'])) {
            return [self::bar('Login outcomes', self::grouped($rows, 'outcome', null), 'attempts')];
        }
        if (isset($columns['type'], $columns['reference'], $columns['units'])) {
            return [self::bar('Documents by transaction type', self::grouped($rows, 'type', null), 'documents'),
                self::bar('Units by transaction type', self::grouped($rows, 'type', 'units'))];
        }
        return [];
    }
}
