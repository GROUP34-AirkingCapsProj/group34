import { buildLabelDocument, buildLabelSheetDocument } from './printBarcodeLabels';
import { LABEL_HEIGHT_MM, LABEL_WIDTH_MM, labelBars, moduleWidthMm } from './barcodeLabel';

/* The bug this file exists to prevent: a sticker printed in the middle of a
   document-sized page. An HTML page could only ask for the label size and be
   overruled by the print dialog; a PDF carries the page size in the file. */

const tags = [
  { serial_number: 'INT-PO-20260828-ZIW7-237-01' },
  { serial_number: 'INT-PO-20260828-ZIW7-237-02' },
];

test('every page is the sticker, not a sheet of paper', () => {
  const doc = buildLabelDocument(tags, 'Rubber Insulation 1/2" x 3/4');

  expect(doc.internal.pageSize.getWidth()).toBeCloseTo(LABEL_WIDTH_MM, 1);
  expect(doc.internal.pageSize.getHeight()).toBeCloseTo(LABEL_HEIGHT_MM, 1);
});

test('one sticker per page so the printer feeds one label each', () => {
  expect(buildLabelDocument(tags, 'Anything').getNumberOfPages()).toBe(2);
  expect(buildLabelDocument(tags.slice(0, 1), 'Anything').getNumberOfPages()).toBe(1);
});

test('the symbol fits the stock, quiet zone included', () => {
  const { widthMm } = labelBars(tags[0].serial_number);

  expect(widthMm).toBeLessThanOrEqual(LABEL_WIDTH_MM);
  // Not so narrow that it is swimming in the middle of the label either.
  expect(widthMm).toBeGreaterThan(LABEL_WIDTH_MM * 0.8);
});

test('bars are drawn at the module width the geometry asked for', () => {
  const value = tags[0].serial_number;
  const { bars } = labelBars(value);
  const narrowest = Math.min(...bars.map((bar) => bar.widthMm));

  // Every bar is a whole number of modules; the thinnest is exactly one.
  expect(narrowest).toBeCloseTo(moduleWidthMm(value), 3);
  expect(bars.length).toBeGreaterThan(20);
});

/* Read back what the sticker actually says. jsPDF writes uncompressed content
   streams, so the drawn strings are in the file verbatim — and unlike spying
   on a draw call, this asserts the thing that reaches the label. */
const drawnText = (doc) => doc.output();

test('a label falls back to its own product name when the batch has no single one', () => {
  const printed = drawnText(buildLabelDocument(
    [
      { serial_number: 'INT-1', product_name: 'Copper Tube' },
      { serial_number: 'INT-2', product_name: 'Split Type AC' },
    ],
    undefined,
  ));

  expect(printed).toContain('Copper Tube');
  expect(printed).toContain('Split Type AC');
});

/* An explicit batch name still wins — the per-product sheet passes one, and
   every tag in that batch really is that product. */
test('an explicit product name still applies to the whole batch', () => {
  const printed = drawnText(buildLabelDocument([{ serial_number: 'INT-1', product_name: 'Ignored' }], 'PE Foam'));

  expect(printed).toContain('PE Foam');
  expect(printed).not.toContain('Ignored');
});

/* The other PDF: plain paper, not label stock. One sticker per A4 sheet is
   correct when the page IS the label and the printer feeds a roll; on a
   document printer it is a ream of paper for a delivery's worth of tags.
   True size is kept — these get cut out and stuck on, and a barcode scaled
   down to fit more per page stops scanning. */
test('a paper sheet carries many labels per page at true size', () => {
  const doc = buildLabelSheetDocument([{ serial_number: 'INT-1', product_name: 'Copper Tube' }]);

  expect(doc.internal.pageSize.getWidth()).toBeCloseTo(297, 0);
  expect(doc.internal.pageSize.getHeight()).toBeCloseTo(210, 0);
});

test('it fills a sheet before starting another', () => {
  const tags = (count) => Array.from({ length: count }, (_, i) => ({ serial_number: `INT-${i}` }));

  expect(buildLabelSheetDocument(tags(14)).getNumberOfPages()).toBe(1);
  expect(buildLabelSheetDocument(tags(15)).getNumberOfPages()).toBe(2);
});

test('every tag on a sheet is drawn, whichever page it lands on', () => {
  const tags = Array.from({ length: 15 }, (_, i) => ({ serial_number: `INT-${i}`, product_name: 'Copper Tube' }));

  const printed = buildLabelSheetDocument(tags).output();

  expect(printed).toContain('INT-0');
  expect(printed).toContain('INT-14');
});
