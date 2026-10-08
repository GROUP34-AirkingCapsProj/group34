import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MaterialTagSheet from './MaterialTagSheet';
import { barcodeLabelsAPI } from '../../services/api';
import { printBarcodeLabels } from '../../utils/printBarcodeLabels';
import { resetLabelPrinterStatus } from '../../hooks/useLabelPrinter';

jest.mock('react-barcode', () => (props) => <div data-testid="barcode">{props.value}</div>);

jest.mock('../../services/api', () => ({
  barcodeLabelsAPI: { status: jest.fn(), print: jest.fn() },
}));

// The PDF itself is verified in printBarcodeLabels.test.js.
jest.mock('../../utils/printBarcodeLabels', () => ({ printBarcodeLabels: jest.fn() }));

jest.mock('../../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
}));

beforeEach(() => {
  jest.clearAllMocks();
  resetLabelPrinterStatus();
  barcodeLabelsAPI.status.mockResolvedValue({ data: { configured: true } });
  barcodeLabelsAPI.print.mockResolvedValue({ message: 'Sticker sent to the printer.' });
});

const tags = [
  { serial_id: 1, serial_number: 'INT-PO-2401-4471-01', supplier_barcode: null },
  { serial_id: 2, serial_number: 'INT-PO-2401-4471-02', supplier_barcode: null },
];

test('renders one barcode per tag', () => {
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
  expect(screen.getAllByTestId('barcode')).toHaveLength(2);
});

test('uses the generated serial number as the barcode value', () => {
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
  const barcodeValues = screen.getAllByTestId('barcode').map((el) => el.textContent);
  expect(barcodeValues).toContain('INT-PO-2401-4471-01');
});

/*
 * A scanned material's item_serial row holds the supplier's own code in
 * BOTH serial_number and supplier_barcode — there is no generated tag to
 * print, and the supplier already printed this one. The sheet excludes it
 * rather than trusting every caller to pre-filter.
 */
test('excludes a scanned material (non-null supplier_barcode) from the sheet', () => {
  const mixed = [
    { serial_number: 'INT-PO-2401-4471-01', supplier_barcode: null },
    { serial_number: 'SUP-9911', supplier_barcode: 'SUP-9911' },
  ];
  render(<MaterialTagSheet tags={mixed} productName="PE Foam" />);
  const barcodeValues = screen.getAllByTestId('barcode').map((el) => el.textContent);
  expect(barcodeValues).toEqual(['INT-PO-2401-4471-01']);
  expect(barcodeValues).not.toContain('SUP-9911');
});

test('renders nothing when every tag is a scanned material', () => {
  const scannedOnly = [{ serial_number: 'SUP-9911', supplier_barcode: 'SUP-9911' }];
  const { container } = render(<MaterialTagSheet tags={scannedOnly} productName="PE Foam" />);
  expect(container).toBeEmptyDOMElement();
});

test('renders nothing when tags is empty', () => {
  const { container } = render(<MaterialTagSheet tags={[]} productName="PE Foam" />);
  expect(container).toBeEmptyDOMElement();
});

test('renders nothing when tags is missing', () => {
  const { container } = render(<MaterialTagSheet productName="PE Foam" />);
  expect(container).toBeEmptyDOMElement();
});

/* Two ways out, and they are not interchangeable: the Zebra prints a sticker,
   the PDF is a file. Offering the printer button when no printer is configured
   would be a control that always fails. */

/* One button, one name, whatever is plugged in. Where the tags come out is
   the system's problem: with a printer configured they go to it, without one
   they go to the print dialog. */

test('print tags goes to the label printer when there is one', async () => {
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);

  userEvent.click(await screen.findByRole('button', { name: /^print tags$/i }));

  await waitFor(() => expect(barcodeLabelsAPI.print).toHaveBeenCalledTimes(1));
  expect(printBarcodeLabels).not.toHaveBeenCalled();
});

test('print tags still prints when no printer is set up', async () => {
  barcodeLabelsAPI.status.mockResolvedValue({ data: { configured: false } });
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);

  // Same control, same words — the clerk is not asked to know what is wired up.
  userEvent.click(await screen.findByRole('button', { name: /^print tags$/i }));

  await waitFor(() => expect(printBarcodeLabels).toHaveBeenCalledTimes(1));
  expect(printBarcodeLabels.mock.calls[0][1]).toBe('PE Foam');
  expect(barcodeLabelsAPI.print).not.toHaveBeenCalled();
});

test('the PDF is a second control only once a printer exists', async () => {
  barcodeLabelsAPI.status.mockResolvedValue({ data: { configured: false } });
  const { unmount } = render(<MaterialTagSheet tags={tags} productName="PE Foam" />);

  await screen.findByRole('button', { name: /^print tags$/i });
  expect(screen.queryByRole('button', { name: /pdf/i })).not.toBeInTheDocument();

  unmount();
  resetLabelPrinterStatus();
  barcodeLabelsAPI.status.mockResolvedValue({ data: { configured: true } });
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);

  expect(await screen.findByRole('button', { name: /print as pdf/i })).toBeInTheDocument();
});
