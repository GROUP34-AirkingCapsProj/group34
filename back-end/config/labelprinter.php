<?php

/*
|--------------------------------------------------------------------------
| Barcode label stock
|--------------------------------------------------------------------------
|
| Only the geometry lives here. Which printer a location uses is stored in
| the database and edited in System Configuration — see
| SystemSetting::labelPrinter(). One address in a file cannot serve several
| warehouses, and a fallback address is worse than none: it would send a
| location's labels to a machine in another building.
|
| These dimensions must match the frontend's LABEL_WIDTH_MM / LABEL_HEIGHT_MM
| in src/admin/utils/barcodeLabel.js, or the PDF and the Zebra disagree about
| what a sticker is. That is why they are here and not in the settings panel:
| an admin who could change one but not the other would break both.
|
*/

return [

    'label' => [
        'width_mm'  => (int) env('LABEL_WIDTH_MM', 100),
        'height_mm' => (int) env('LABEL_HEIGHT_MM', 25),
        /* 203 dpi is the common Zebra head; 300 dpi models exist. Wrong here
           and every measurement above is off by half or a third. */
        'dpi'       => (int) env('LABEL_PRINTER_DPI', 203),
    ],

];
