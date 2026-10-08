import JsBarcode from 'jsbarcode';

/**
 * Geometry for the barcode stickers, in millimetres.
 *
 * These print on a roll-fed thermal label printer, one sticker per page.
 * Change LABEL_WIDTH_MM / LABEL_HEIGHT_MM here and every caller follows —
 * this is the only place the stock size is written down.
 */
export const LABEL_WIDTH_MM = 100;
export const LABEL_HEIGHT_MM = 25;

/* Ink-free border the printer needs, and the sticker's own die-cut slop. */
export const PADDING_MM = 1.2;

/* Blank space either side of the symbol. Not decoration: a scanner needs it
   to find the symbol's edges, and 10 modules is the CODE128 minimum. */
export const QUIET_MODULES = 10;

/* Bars as tall as the stock allows. Height does not carry data, but a taller
   symbol gives the scanner more chances at a clean sweep — which is what
   buys back the margin lost to a narrow module on stock this small. */
export const BAR_HEIGHT_MM = 14;

/* An X-dimension below this is inside CODE128's tolerance but outside what a
   cheap handheld reads reliably. Codes long enough to force it still print —
   a clipped symbol would be worse than a tight one — but the caller can warn. */
export const COMFORTABLE_MODULE_MM = 0.25;

/* Conservative CODE128 module count: 11 per character plus start, checksum,
   stop and no assumption that digits pack two to a symbol. Over-estimating
   only narrows the bars, which is the safe direction to be wrong in. */
const modulesFor = (value) => 11 * (String(value).length + 2) + 35;

/**
 * Bar width for one code, in millimetres.
 *
 * Deliberately unclamped at the bottom: the symbol must fit the stock. A
 * module wide enough to overflow a 50mm label would print clipped, and half a
 * barcode scans as nothing at all.
 */
export function moduleWidthMm(value) {
  const available = LABEL_WIDTH_MM - PADDING_MM * 2;
  return Math.min(0.5, available / (modulesFor(value) + QUIET_MODULES * 2));
}

/** True when a code is long enough that its bars end up hard to scan. */
export const isTightFit = (value) => moduleWidthMm(value) < COMFORTABLE_MODULE_MM;

/**
 * The bars for one code, measured in millimetres across the label.
 *
 * JsBarcode draws into a detached SVG — nothing is added to the page — and the
 * rects it produces are read straight back out. Encoding CODE128 by hand here
 * would be a second implementation of something already in the bundle, and a
 * wrong one is a barcode that scans as the wrong item.
 */
export function labelBars(value) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  const moduleMm = moduleWidthMm(value);

  JsBarcode(svg, String(value), {
    format: 'CODE128',
    width: moduleMm,
    height: BAR_HEIGHT_MM,
    margin: 0,
    marginLeft: moduleMm * QUIET_MODULES,
    marginRight: moduleMm * QUIET_MODULES,
    displayValue: false,
    /* Drawn as PDF rectangles, so the background rect would only sit on top of
       the bars as an opaque white block. */
    background: null,
  });

  /* Bars live inside a group the renderer offsets by the left quiet zone. */
  const group = svg.querySelector('g');
  const offset = groupOffsetX(group);

  /* The renderer writes lengths with a unit ("97.6px"), so these are parsed
     rather than cast. The numbers themselves are already in mm — the module
     width handed in above is what every length is a multiple of. */
  const bars = Array.from((group || svg).querySelectorAll('rect')).map((rect) => ({
    xMm: offset + parseFloat(rect.getAttribute('x')) || 0,
    widthMm: parseFloat(rect.getAttribute('width')) || 0,
  }));

  return { bars, widthMm: parseFloat(svg.getAttribute('width')) || 0 };
}

/* `transform="translate(x, y)"` — the only transform this renderer emits. */
function groupOffsetX(group) {
  const match = /translate\(\s*([-\d.]+)/.exec(group?.getAttribute('transform') || '');
  return match ? Number(match[1]) : 0;
}
