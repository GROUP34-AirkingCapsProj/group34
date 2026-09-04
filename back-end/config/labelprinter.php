<?php

/*
|--------------------------------------------------------------------------
| Barcode label printer
|--------------------------------------------------------------------------
|
| The Zebra that prints the material tags. A browser cannot choose a printer
| or send raw commands, so the sticker is built as ZPL here and handed to the
| printer by the server. Leave `transport` empty and the direct-print endpoint
| reports itself as unconfigured — the PDF path in the UI keeps working either
| way.
|
| The label stock must match the frontend's LABEL_WIDTH_MM / LABEL_HEIGHT_MM
| in src/admin/utils/barcodeLabel.js, or the PDF and the Zebra disagree about
| what a sticker is.
|
*/

return [

    /*
    | 'tcp'   — the printer has an IP and accepts raw jobs (Zebra's default is
    |           port 9100). The usual setup for a networked label printer.
    | 'share' — a Windows printer share or local port the server can write to,
    |           e.g. \THIS-PC\Zebra. Only works when the server and the
    |           printer are on the same machine or network share.
    | null    — no printer wired up; direct printing is disabled.
    */
    'transport' => env('LABEL_PRINTER_TRANSPORT'),

    'host'    => env('LABEL_PRINTER_HOST'),
    'port'    => (int) env('LABEL_PRINTER_PORT', 9100),
    'timeout' => (float) env('LABEL_PRINTER_TIMEOUT', 5),

    'share' => env('LABEL_PRINTER_SHARE'),

    'label' => [
        'width_mm'  => (int) env('LABEL_WIDTH_MM', 100),
        'height_mm' => (int) env('LABEL_HEIGHT_MM', 25),
        /* 203 dpi is the common Zebra head; 300 dpi models exist. Wrong here
           and every measurement above is off by half or a third. */
        'dpi'       => (int) env('LABEL_PRINTER_DPI', 203),
    ],

];
