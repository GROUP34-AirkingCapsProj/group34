<?php

namespace App\Reports;

/**
 * What a report produced.
 *
 * `columns` + `rows` are the flat shape, always present, and are what CSV
 * writes. `sections` is optional and used only by the PDF renderer, for
 * reports that read better as several tables than as one wide one — a
 * spreadsheet cannot filter stacked sections, so CSV never sees them.
 */
class ReportDataset
{
    /**
     * `rows` is the canonical data and must always be populated when the
     * report has results — it is what `isEmpty()` checks and what CSV
     * writes. `sections` is an optional, PDF-only regrouping of those same
     * rows into several focused tables; it is never a substitute for rows,
     * so a dataset cannot carry sections without the rows they were
     * derived from.
     *
     * @param array<string,string> $columns  request key => display label
     * @param array<int,array>     $rows     each keyed by the column keys
     * @param array<string,string> $summary  label => formatted total
     * @param array<int,array>     $sections ['title' =>, 'columns' =>, 'rows' =>]
     * @param array<int,string>    $notes    caveats printed under the tables
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $summary = [],
        public readonly array $sections = [],
        public readonly array $notes = [],
    ) {
        if ($this->rows === [] && $this->sections !== []) {
            throw new \InvalidArgumentException(
                'ReportDataset sections are a presentation view of rows and cannot stand alone: rows is empty but sections is not.'
            );
        }
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
