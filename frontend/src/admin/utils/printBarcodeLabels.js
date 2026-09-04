import { jsPDF } from 'jspdf';
import {
  BAR_HEIGHT_MM,
  LABEL_HEIGHT_MM,
  LABEL_WIDTH_MM,
  labelBars,
} from './barcodeLabel';

/**
 * Builds the barcode stickers as a PDF and sends it to the printer.
 *
 * Printing an HTML page never worked for this: `@page { size }` is only a
 * request, and a browser honours it just when the selected printer already
 * offers that stock — otherwise the sticker lands in the middle of an A4
 * sheet, wrapped in the browser's own header and footer. A PDF carries its
 * page size in the file, so the page IS the sticker whatever the dialog says,
 * and a PDF prints without browser furniture.
 *
 * Every measurement below is in millimetres, straight onto the label.
 */

/* Room for the descenders of the name above and the code below. */
const NAME_BASELINE_MM = 3.7;
const BARS_TOP_MM = 4.6;
const VALUE_BASELINE_MM = 22.2;

const NAME_PT = 7;
const VALUE_PT = 8;

export function buildLabelDocument(tags, productName) {
  const doc = new jsPDF({
    unit: 'mm',
    format: [LABEL_WIDTH_MM, LABEL_HEIGHT_MM],
    orientation: 'landscape',
  });

  tags.forEach((tag, index) => {
    if (index > 0) {
      /* One sticker per page, so the printer feeds exactly one label each. */
      doc.addPage([LABEL_WIDTH_MM, LABEL_HEIGHT_MM], 'landscape');
    }

    const value = String(tag.serial_number);
    const { bars, widthMm } = labelBars(value);
    /* Centred, so the quiet zone is even on both sides however long the code. */
    const originX = (LABEL_WIDTH_MM - widthMm) / 2;

    if (productName) {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(NAME_PT);
      doc.text(truncateToWidth(doc, productName, LABEL_WIDTH_MM - 4), LABEL_WIDTH_MM / 2, NAME_BASELINE_MM, { align: 'center' });
    }

    doc.setFillColor(0, 0, 0);
    bars.forEach((bar) => {
      doc.rect(originX + bar.xMm, BARS_TOP_MM, bar.widthMm, BAR_HEIGHT_MM, 'F');
    });

    /* Printed as text rather than by the barcode library so a worn sticker can
       still be keyed in by hand. */
    doc.setFont('courier', 'bold');
    doc.setFontSize(VALUE_PT);
    doc.text(value, LABEL_WIDTH_MM / 2, VALUE_BASELINE_MM, { align: 'center' });
  });

  return doc;
}

/* A product name too long for the stock is cut rather than wrapped: a second
   line would push into the bars. */
function truncateToWidth(doc, text, maxMm) {
  if (doc.getTextWidth(text) <= maxMm) return text;
  let cut = text;
  while (cut.length > 1 && doc.getTextWidth(`${cut}…`) > maxMm) {
    cut = cut.slice(0, -1);
  }
  return `${cut}…`;
}

export function printBarcodeLabels(tags, productName, { onNotice } = {}) {
  const printable = (tags || []).filter((tag) => tag?.serial_number);
  if (printable.length === 0) return;

  const doc = buildLabelDocument(printable, productName);
  doc.autoPrint();

  /* Opened straight from the click — the document is built synchronously, so
     the window still counts as user-initiated and survives the pop-up
     blocker. */
  const opened = window.open(doc.output('bloburl'), '_blank');
  if (!opened) {
    doc.save('barcode-stickers.pdf');
    onNotice?.('Pop-ups are blocked, so the stickers were downloaded as a PDF instead. Open it and print.');
    return;
  }
}

export default printBarcodeLabels;
