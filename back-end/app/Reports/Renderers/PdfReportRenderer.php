<?php

namespace App\Reports\Renderers;

use App\Models\SystemSetting;
use App\Reports\ReportDataset;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders through the shared reports/document blade, matching the paper-size
 * and company-header conventions of the existing documents (see
 * AdjustmentController::pdf). Sections, when present, are rendered as
 * several focused tables instead of the flat table — see the blade's
 * @forelse($sections)/@empty split.
 */
class PdfReportRenderer
{
    private const VALID_PAPER_SIZES = ['a4', 'letter', 'legal', 'a5'];

    /** @param array<string,string> $meta filter summary shown under the title */
    public function render(ReportDataset $dataset, string $title, array $meta): string
    {
        // SystemSetting::pdfPaperSize() already normalizes to lowercase and
        // falls back to 'a4' on its own, so this in_array() check is just a
        // defensive guard against the const list ever drifting from it.
        $paperSize = SystemSetting::pdfPaperSize();
        if (! in_array($paperSize, self::VALID_PAPER_SIZES, true)) {
            $paperSize = 'a4';
        }

        return Pdf::loadView('reports.document', [
            'title'        => $title,
            'meta'         => $meta,
            'columns'      => $dataset->columns,
            'rows'         => $this->formatRows($dataset->rows, $dataset->columnFormats),
            'sections'     => $this->formatSections($dataset->sections, $dataset->columnFormats),
            'summary'      => $dataset->summary,
            'notes'        => $dataset->notes,
            'generated_at' => now()->format('Y-m-d H:i'),
            'company'      => [
                'name'    => SystemSetting::companyName(),
                'address' => SystemSetting::companyAddress(),
                'email'   => SystemSetting::companyEmail(),
                'phone'   => SystemSetting::companyPhone(),
            ],
        ])->setPaper($paperSize)->output();
    }

    /**
     * Definitions emit raw numbers (so CSV stays machine-readable); this is
     * where the PDF applies each column's declared display format
     * (thousands separators, decimals) — right before the row reaches the
     * blade, which just echoes whatever it is handed.
     *
     * @param array<int,array>     $rows
     * @param array<string,string> $formats column key => ReportDataset::FORMAT_*
     * @return array<int,array>
     */
    private function formatRows(array $rows, array $formats): array
    {
        if ($formats === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($formats as $key => $format) {
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $row[$key] = ReportDataset::formatValue($row[$key], $format);
                }
            }
        }

        return $rows;
    }

    /**
     * Sections reuse the same rows as the flat table, just regrouped, so the
     * same column-format map applies to them.
     *
     * @param array<int,array>     $sections
     * @param array<string,string> $formats
     * @return array<int,array>
     */
    private function formatSections(array $sections, array $formats): array
    {
        if ($formats === []) {
            return $sections;
        }

        return array_map(
            fn (array $section) => array_merge($section, ['rows' => $this->formatRows($section['rows'], $formats)]),
            $sections
        );
    }
}
