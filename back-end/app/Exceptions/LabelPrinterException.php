<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The label printer could not be reached or is not configured.
 *
 * Its message is shown to the person at the screen, so it names the thing to
 * fix rather than the layer that failed.
 */
class LabelPrinterException extends RuntimeException
{
}
