<?php

namespace App\Reports;

use RuntimeException;

/**
 * Thrown when a report's build() produced no rows. The runner aborts before
 * writing a file or a report_runs row — an empty PDF in "Recent Reports" is
 * exactly the junk that makes a reports page untrustworthy.
 */
class EmptyReportException extends RuntimeException
{
}
