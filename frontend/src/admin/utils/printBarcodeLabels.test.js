import { buildLabelDocument } from './printBarcodeLabels';
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
