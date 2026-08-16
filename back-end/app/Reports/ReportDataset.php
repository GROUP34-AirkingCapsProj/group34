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
     * Closed set of display formats a definition may declare for a column.
     * Deliberately small — this is a lookup key for number_format(), not a
     * formatting mini-language. Add to this set only if a genuinely new
     * shape of number shows up (e.g. percentages), not as a one-off.
     */
    public const FORMAT_MONEY   = 'money';
    public const FORMAT_INTEGER = 'integer';

    /**
     * `rows` is the canonical data and must always be populated when the
     * report has results — it is what `isEmpty()` checks and what CSV
     * writes. `sections` is an optional, PDF-only regrouping of those same
     * rows into several focused tables; it is never a substitute for rows,
     * so a dataset cannot carry sections without the rows they were
     * derived from.
     *
     * Row values themselves must be raw (a number, not a formatted string)
     * — CSV writes them as-is so spreadsheets and pandas can sum/pivot them,
     * and it is the one dataset shape both renderers share. `columnFormats`
     * is how a definition tells the PDF renderer how to *display* a raw
     * value; CSV ignores it entirely by design. `summary`, by contrast, is
     * PDF/CSV footer text only — nothing downstream parses it — so it is
     * fine, and simpler, for it to stay a pre-formatted string.
     *
     * @param array<string,string> $columns       request key => display label
     * @param array<int,array>     $rows          each keyed by the column keys, values raw (numbers stay numbers)
     * @param array<string,string> $summary       label => formatted total
     * @param array<int,array>     $sections      ['title' =>, 'columns' =>, 'rows' =>] — rows here follow the same raw-value rule
     * @param array<int,string>    $notes         caveats printed under the tables
     * @param array<string,string> $columnFormats column key => one of self::FORMAT_*, applied by the PDF renderer only
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $summary = [],
        public readonly array $sections = [],
        public readonly array $notes = [],
        public readonly array $columnFormats = [],
    ) {
        if ($this->rows === [] && $this->sections !== []) {
            throw new \InvalidArgumentException(
                'ReportDataset sections are a presentation view of rows and cannot stand alone: rows is empty but sections is not.'
            );
        }
    }

    /** Render one raw value for display under a declared format. */
    public static function formatValue(mixed $value, string $format): string
    {
        return match ($format) {
            self::FORMAT_MONEY   => number_format((float) $value, 2),
            self::FORMAT_INTEGER => number_format((float) $value),
            default              => (string) $value,
        };
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
