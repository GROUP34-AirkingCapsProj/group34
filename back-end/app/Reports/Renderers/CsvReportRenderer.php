<?php

namespace App\Reports\Renderers;

use App\Reports\ReportDataset;

/**
 * Flat CSV: one header row, then one row per data row.
 *
 * The header row MUST be line 1 — spreadsheets and scripts that consume this
 * file assume the first row is the column header, and a leading title row
 * would shift that assumption and break auto-filter/sort. So the title and
 * meta (the applied filters) are appended as a footer block after the data
 * instead of prepended before it.
 *
 * Sections are deliberately ignored — a spreadsheet cannot filter stacked
 * tables, so a multi-section report flattens to one row per subject with every
 * metric as its own column. Written through fputcsv so a product name
 * containing a comma or a quote cannot shift the remaining columns.
 *
 * `dataset->columnFormats` is also deliberately ignored: it is a display
 * instruction for the PDF, and row values are already raw numbers by
 * contract, so a numeric column lands here unquoted and summable instead of
 * as a formatted string like "72,000.00" that spreadsheets read as text.
 */
class CsvReportRenderer
{
    /** @param array<string,string> $meta filter summary, appended as a footer */
    public function render(ReportDataset $dataset, string $title, array $meta): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, array_values($dataset->columns));

        foreach ($dataset->rows as $row) {
            $line = [];
            foreach (array_keys($dataset->columns) as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($handle, $line);
        }

        if ($dataset->summary !== []) {
            fputcsv($handle, []);
            foreach ($dataset->summary as $label => $value) {
                fputcsv($handle, [$label, $value]);
            }
        }

        if ($dataset->notes !== []) {
            fputcsv($handle, []);
            foreach ($dataset->notes as $note) {
                fputcsv($handle, [$note]);
            }
        }

        fputcsv($handle, []);
        fputcsv($handle, [$title]);
        foreach ($meta as $label => $value) {
            fputcsv($handle, [$label, $value]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
