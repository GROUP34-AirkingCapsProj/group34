<?php

namespace App\Services;

/**
 * Builds ZPL for the barcode stickers.
 *
 * ZPL is what a Zebra speaks natively: the printer renders the symbol itself
 * from the code, so nothing is rasterised on the way and the bars land on exact
 * printer dots. That is the difference between a sticker that scans first time
 * and one that scans sometimes.
 *
 * Everything here is in dots. A 203 dpi head puts 8 dots in a millimetre, so
 * the label size in config becomes a dot canvas the fields are placed on.
 */
class ZplLabelBuilder
{
    /* CODE128 needs a blank margin either side or a scanner cannot find the
       symbol's edges. 10 modules is the specified minimum. */
    private const QUIET_MODULES = 10;

    /* Narrowest bar the printer is asked for. Below 2 dots the bar edges stop
       landing on dot boundaries and the widths drift; above 4 a long code no
       longer fits the stock. */
    private const MIN_MODULE_DOTS = 2;
    private const MAX_MODULE_DOTS = 4;

    /* Backslash, ZPL's own escape character. Held as a code point because a
       literal one is mangled by half the things that touch a source file
       between here and the printer. */
    private const ESCAPE_CODE = 92;

    public function __construct(
        private int $widthMm = 100,
        private int $heightMm = 25,
        private int $dpi = 203,
        private float $paddingMm = 1.2,
    ) {
    }

    /** One ^XA…^XZ block per sticker, concatenated into a single job. */
    public function build(array $labels): string
    {
        return implode('', array_map(
            fn (array $label) => $this->buildOne(
                (string) ($label['value'] ?? ''),
                isset($label['name']) ? (string) $label['name'] : null,
            ),
            $labels,
        ));
    }

    public function buildOne(string $value, ?string $name = null): string
    {
        $canvasWidth  = $this->dots($this->widthMm);
        $canvasHeight = $this->dots($this->heightMm);

        $moduleDots = $this->moduleDots($value);
        $symbolDots = $this->symbolModules($value) * $moduleDots;
        /* Centred, so the quiet zone is even either side however long the code. */
        $symbolX = max(0, intdiv($canvasWidth - $symbolDots, 2));

        $nameHeight  = $this->dots(2.5);
        $valueHeight = $this->dots(2.8);
        $barsTop     = $this->dots(4.4);
        $barsHeight  = $this->dots(13.6);
        $valueTop    = $barsTop + $barsHeight + $this->dots(1.0);

        $zpl  = '^XA';
        /* UTF-8, so a product name with a degree sign or an inch mark prints as
           itself rather than as a box. */
        $zpl .= '^CI28';
        $zpl .= '^LH0,0';
        $zpl .= "^PW{$canvasWidth}";
        $zpl .= "^LL{$canvasHeight}";

        if ($name !== null && trim($name) !== '') {
            $top = $this->dots(0.9);
            $zpl .= "^FO0,{$top}^FB{$canvasWidth},1,0,C,0";
            $zpl .= "^A0N,{$nameHeight},{$nameHeight}";
            $zpl .= '^FD' . $this->escape($name) . '^FS';
        }

        /* ^BY sets the module width; ^BC draws CODE128 with no interpretation
           line — the code is written below as text so its size is ours to set. */
        $zpl .= "^BY{$moduleDots},2,{$barsHeight}";
        $zpl .= "^FO{$symbolX},{$barsTop}";
        $zpl .= "^BCN,{$barsHeight},N,N,N";
        $zpl .= '^FD' . $this->escape($value) . '^FS';

        $zpl .= "^FO0,{$valueTop}^FB{$canvasWidth},1,0,C,0";
        $zpl .= "^A0N,{$valueHeight},{$valueHeight}";
        $zpl .= '^FD' . $this->escape($value) . '^FS';

        $zpl .= '^XZ';

        return $zpl;
    }

    /**
     * Widest bar the code can use and still fit the stock.
     *
     * Clamped at both ends: too narrow and the printer smears the bars, too
     * wide and the symbol runs off the label. A code long enough to need less
     * than the minimum still prints at the minimum — a symbol that overflows
     * is clipped, and half a barcode scans as nothing.
     */
    public function moduleDots(string $value): int
    {
        $available = $this->dots($this->widthMm - $this->paddingMm * 2);
        $fits = intdiv($available, $this->symbolModules($value));

        return max(self::MIN_MODULE_DOTS, min(self::MAX_MODULE_DOTS, $fits));
    }

    /** True when the code cannot fit the stock at the narrowest usable bar. */
    public function overflows(string $value): bool
    {
        $available = $this->dots($this->widthMm - $this->paddingMm * 2);

        return $this->symbolModules($value) * $this->moduleDots($value) > $available;
    }

    /* Conservative CODE128 module count — 11 per character plus start,
       checksum and stop, with no assumption that digits pack two to a symbol —
       plus the quiet zone either side. Over-estimating only narrows the bars,
       which is the safe direction to be wrong in. */
    private function symbolModules(string $value): int
    {
        return 11 * (strlen($value) + 2) + 35 + self::QUIET_MODULES * 2;
    }

    private function dots(float $mm): int
    {
        return (int) round($mm * $this->dpi / 25.4);
    }

    /* ^, ~ and \ are ZPL's own control characters. Left raw, a product name
       containing one would be read as a command instead of as text. */
    private function escape(string $text): string
    {
        $esc = chr(self::ESCAPE_CODE);

        return str_replace(
            [$esc, '^', '~'],
            [$esc . $esc, $esc . '5E', $esc . '7E'],
            $text,
        );
    }
}
