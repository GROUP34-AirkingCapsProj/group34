import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MaterialTagSheet from './MaterialTagSheet';

jest.mock('react-barcode', () => (props) => <div data-testid="barcode">{props.value}</div>);

const tags = [
  { serial_number: 'INT-PO-2401-4471-01', supplier_barcode: null },
  { serial_number: 'INT-PO-2401-4471-02', supplier_barcode: null },
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

test('print button calls onPrint when provided', () => {
  const onPrint = jest.fn();
  render(<MaterialTagSheet tags={tags} productName="PE Foam" onPrint={onPrint} />);
  userEvent.click(screen.getByRole('button', { name: /print tags/i }));
  expect(onPrint).toHaveBeenCalledTimes(1);
});
