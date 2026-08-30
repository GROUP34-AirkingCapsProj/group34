import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MaterialTagSheet from './MaterialTagSheet';

jest.mock('react-barcode', () => (props) => <div data-testid="barcode">{props.value}</div>);

const tags = [
  { serial_number: 'INT-PO-2401-4471-01', supplier_barcode: 'SUP-9911' },
  { serial_number: 'INT-PO-2401-4471-02', supplier_barcode: null },
];

test('renders one barcode per tag', () => {
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
  expect(screen.getAllByTestId('barcode')).toHaveLength(2);
});

test('uses the generated serial number as the barcode value, not the supplier barcode', () => {
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
  const barcodeValues = screen.getAllByTestId('barcode').map((el) => el.textContent);
  expect(barcodeValues).toContain('INT-PO-2401-4471-01');
  expect(barcodeValues).not.toContain('SUP-9911');
});

test('shows the supplier barcode as reference text when present', () => {
  render(<MaterialTagSheet tags={tags} productName="PE Foam" />);
  expect(screen.getByText(/Supplier: SUP-9911/i)).toBeInTheDocument();
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
