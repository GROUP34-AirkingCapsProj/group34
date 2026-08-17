<?php

namespace App\Reports;

use InvalidArgumentException;

/**
 * Thrown when a report exists but does not declare the requested format —
 * Low Stock is csv-only, so asking it for a PDF lands here.
 *
 * Separate from the bare \InvalidArgumentException that an unknown report key
 * raises, because the two deserve different answers: a missing report is a
 * 404, while a real report asked for the wrong format is a 422 whose message
 * names the format. Collapsing both into "Report not found" told a client the
 * report did not exist and discarded the message describing what was actually
 * wrong.
 *
 * Extends InvalidArgumentException so callers that catch the general case
 * still catch this one.
 */
class UnsupportedFormatException extends InvalidArgumentException
{
}
