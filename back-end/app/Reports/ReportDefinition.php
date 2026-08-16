<?php

namespace App\Reports;

use App\Models\User;

interface ReportDefinition
{
    /** Stable slug used in URLs and filenames, e.g. "inventory-summary". */
    public function key(): string;

    public function title(): string;

    public function description(): string;

    /** @return ReportParameter[] */
    public function parameters(): array;

    /** @return string[] subset of ['pdf', 'csv'] */
    public function formats(): array;

    /** dompdf dies on very large tables; above this, PDF is refused. */
    public function pdfRowCap(): int;

    /** Whether this user may run it at all — drives catalog visibility. */
    public function availableTo(User $user): bool;

    public function build(ReportParameters $params, User $user): ReportDataset;
}
