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

/* One sticker, drawn with its top-left corner at (x, y). Every measurement
   inside a label is relative to that corner, so the same drawing serves a
   page that IS the label and a slot on a sheet of paper. */
function drawLabel(doc, tag, productName, x, y) {
  const value = String(tag.serial_number);
  const { bars, widthMm } = labelBars(value);
  /* Centred, so the quiet zone is even on both sides however long the code. */
  const originX = x + (LABEL_WIDTH_MM - widthMm) / 2;

  /* A batch name wins when the caller has one — the per-product sheet
     prints one product, and every tag in it really is that product. The
     printing page prints a whole delivery, so there each label falls back
     to its own. */
  const label = productName || tag.product_name;

  if (label) {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(NAME_PT);
    doc.text(truncateToWidth(doc, label, LABEL_WIDTH_MM - 4), x + LABEL_WIDTH_MM / 2, y + NAME_BASELINE_MM, { align: 'center' });
  }

  doc.setFillColor(0, 0, 0);
  bars.forEach((bar) => {
    doc.rect(originX + bar.xMm, y + BARS_TOP_MM, bar.widthMm, BAR_HEIGHT_MM, 'F');
  });

  /* Printed as text rather than by the barcode library so a worn sticker can
     still be keyed in by hand. */
  doc.setFont('courier', 'bold');
  doc.setFontSize(VALUE_PT);
  doc.text(value, x + LABEL_WIDTH_MM / 2, y + VALUE_BASELINE_MM, { align: 'center' });
}

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

    drawLabel(doc, tag, productName, 0, 0);
  });

  return doc;
}

/* Paper margin, and the space between two stickers. The gap is what you cut
   along, so it is small on purpose — every millimetre here is a millimetre
   fewer labels on the sheet. */
const SHEET_MARGIN_MM = 10;
const SHEET_GUTTER_MM = 2;

/**
 * The same stickers laid out on A4, for a document printer.
 *
 * buildLabelDocument's page IS the label, which is right for a roll-fed
 * thermal printer and wasteful on paper: a delivery's worth of tags becomes a
 * ream, one sticker per sheet. Here the labels tile instead, to be cut out.
 *
 * They keep their true size. Scaling them down to fit more per page would
 * narrow the bars past what a handheld reads, and a sheet of labels that will
 * not scan costs more than the paper it saved.
 */
export function buildLabelSheetDocument(tags, productName) {
  const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'landscape' });

  const usableWidth = doc.internal.pageSize.getWidth() - SHEET_MARGIN_MM * 2;
  const usableHeight = doc.internal.pageSize.getHeight() - SHEET_MARGIN_MM * 2;

  /* Derived, not hardcoded: LABEL_WIDTH_MM is configurable, and a grid that
     assumed today's stock would silently overflow the page on a wider one. */
  const columns = Math.max(1, Math.floor((usableWidth + SHEET_GUTTER_MM) / (LABEL_WIDTH_MM + SHEET_GUTTER_MM)));
  const rows = Math.max(1, Math.floor((usableHeight + SHEET_GUTTER_MM) / (LABEL_HEIGHT_MM + SHEET_GUTTER_MM)));
  const perPage = columns * rows;

  tags.forEach((tag, index) => {
    if (index > 0 && index % perPage === 0) {
      doc.addPage('a4', 'landscape');
    }

    const slot = index % perPage;
    const x = SHEET_MARGIN_MM + (slot % columns) * (LABEL_WIDTH_MM + SHEET_GUTTER_MM);
    const y = SHEET_MARGIN_MM + Math.floor(slot / columns) * (LABEL_HEIGHT_MM + SHEET_GUTTER_MM);

    /* A hairline for the scissors. Without it the stickers float on a white
       page and every cut is a guess. */
    doc.setDrawColor(190);
    doc.setLineWidth(0.1);
    doc.rect(x, y, LABEL_WIDTH_MM, LABEL_HEIGHT_MM);

    drawLabel(doc, tag, productName, x, y);
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

function sendToDialog(doc, filename, onNotice) {
  doc.autoPrint();

  /* Opened straight from the click — the document is built synchronously, so
     the window still counts as user-initiated and survives the pop-up
     blocker. */
  const opened = window.open(doc.output('bloburl'), '_blank');
  if (!opened) {
    doc.save(filename);
    onNotice?.('Pop-ups are blocked, so the stickers were downloaded as a PDF instead. Open it and print.');
  }
}

export function printBarcodeLabels(tags, productName, { onNotice } = {}) {
  const printable = (tags || []).filter((tag) => tag?.serial_number);
  if (printable.length === 0) return;

  sendToDialog(buildLabelDocument(printable, productName), 'barcode-stickers.pdf', onNotice);
}

/** The paper route: the same stickers tiled on A4, to be cut out. */
export function printLabelSheet(tags, productName, { onNotice } = {}) {
  const printable = (tags || []).filter((tag) => tag?.serial_number);
  if (printable.length === 0) return;

  sendToDialog(buildLabelSheetDocument(printable, productName), 'barcode-stickers.pdf', onNotice);
}

export default printBarcodeLabels;
