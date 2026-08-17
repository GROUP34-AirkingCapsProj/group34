<?php

namespace App\Reports;

use RuntimeException;

/**
 * Thrown when a PDF request's row count exceeds the definition's
 * pdfRowCap(). dompdf exhausts memory on very large tables; failing clearly
 * beats a 502 from a dead worker. The caller should be told to choose CSV or
 * narrow their filters.
 */
class RowCapExceededException extends RuntimeException
{
}
