import { render, screen, fireEvent, act, within } from '@testing-library/react';
import ReceivePanel from './ReceivePanel';
import { transfersAPI } from '../../services/api';

jest.mock('../../hooks/useBarcodeCamera', () => ({
  useBarcodeCamera: () => ({ cameraActive: false, cameraDevices: [], startCamera: jest.fn(), stopCamera: jest.fn() }),
}));
jest.mock('../../services/api', () => ({ transfersAPI: { receiveScan: jest.fn() }, getToken: jest.fn() }));
jest.mock('../../utils/toast', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const transfer = { transfer_id: 1, details: [{
  transfer_detail_id: 8, product_id: 5, quantity_transferred: 10, quantity_received: 0,
  product: { product_name: 'Copper tube', product_type: 'installation_material' },
}] };

test('shows the selected product and package quantity, then updates progress without a popup', async () => {
  transfersAPI.receiveScan.mockResolvedValue({ success: true, data: {
    transfer_detail_id: 8, scan_id: 4, quantity: 5, quantity_received: 5, remaining: 5,
  } });
  render(<ReceivePanel transfer={transfer} />);
  fireEvent.click(screen.getByText('Copper tube'));
  const status = screen.getByRole('region', { name: 'Scanning status' });
  expect(within(status).getByText('Copper tube')).toBeInTheDocument();
  expect(status.closest('.bs-camera-box')).not.toBeNull();
  expect(within(status).getByText(/Next scan:/)).toHaveTextContent('1 packages');
  const input = screen.getByPlaceholderText(/Scan or type barcode/);
  fireEvent.change(input, { target: { value: 'TUBE-01' } });
  await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Submit' })); });
  expect(within(status).getByText(/Scan successful/)).toBeInTheDocument();
  expect(within(status).getByText(/Recorded:/)).toHaveTextContent('5 / 10 pieces');
  expect(input).toHaveFocus();
  expect(transfersAPI.receiveScan).toHaveBeenCalledWith(1, expect.objectContaining({ quantity: 1, quantity_unit: 'package' }));
});

test('a rejected scan leaves quantities unchanged and allows a retry', async () => {
  transfersAPI.receiveScan.mockRejectedValue(new Error('Wrong product barcode'));
  render(<ReceivePanel transfer={transfer} />);
  fireEvent.click(screen.getByText('Copper tube'));
  const input = screen.getByPlaceholderText(/Scan or type barcode/);
  fireEvent.change(input, { target: { value: 'WRONG' } });
  await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Submit' })); });
  expect(screen.getByText(/Scan not accepted/)).toBeInTheDocument();
  expect(screen.getByText('Wrong product barcode')).toBeInTheDocument();
  expect(screen.getByRole('region', { name: 'Scanning status' })).toHaveTextContent('0 / 10 pieces');
  expect(input).toBeEnabled();
  expect(input).toHaveFocus();
});

test('a server rejection in a resolved response never displays success', async () => {
  transfersAPI.receiveScan.mockResolvedValue({ success: false, message: 'Quantity exceeds remaining stock' });
  render(<ReceivePanel transfer={transfer} />);
  fireEvent.click(screen.getByText('Copper tube'));
  fireEvent.change(screen.getByPlaceholderText(/Scan or type barcode/), { target: { value: 'TUBE' } });
  await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Submit' })); });
  expect(screen.getByText(/Scan not accepted/)).toBeInTheDocument();
  expect(screen.queryByText(/Scan successful/)).not.toBeInTheDocument();
  expect(screen.getByRole('region', { name: 'Scanning status' })).toHaveTextContent('0 / 10 pieces');
});
