import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import VerifyPanel from './VerifyPanel';
import { transfersAPI } from '../../services/api';

jest.mock('../../services/api', () => ({ transfersAPI: { sourceAvailability: jest.fn(), verify: jest.fn() } }));
jest.mock('../../utils/toast', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const locations = [
  { location_id: 1, location_name: 'Warehouse A', location_type: 'warehouse' },
  { location_id: 2, location_name: 'Warehouse B', location_type: 'warehouse' },
  { location_id: 3, location_name: 'Warehouse C', location_type: 'warehouse' },
];
const transfer = { transfer_id: 7, transfer_number: 'TR-7', to_location_id: 1, details: [
  { transfer_detail_id: 8, product_id: 5, quantity_requested: 10, product: { product_name: 'TCL Aircon' } },
  { transfer_detail_id: 9, product_id: 6, quantity_requested: 2, product: { product_name: 'Unavailable item' } },
] };
const chooseSource = async (value = '2') => {
  await screen.findByText('Available stock by warehouse');
  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value } });
};
beforeEach(() => {
  transfersAPI.sourceAvailability.mockResolvedValue({ data: { 2: { 5: { available: 5 } }, 3: { 5: { available: 10 } } } });
  transfersAPI.verify.mockResolvedValue({ success: true });
});

test('shows warehouse stock, caps 10 requested to 5 available, and submits only available lines', async () => {
  render(<VerifyPanel transfer={transfer} locations={locations} />);
  await chooseSource();
  expect(screen.getByText('5 available')).toBeInTheDocument();
  const inputs = screen.getAllByRole('spinbutton');
  expect(inputs[0]).toHaveValue(5);
  expect(inputs[1]).toHaveValue(0);
  expect(screen.getByText(/unfulfilled 5/)).toBeInTheDocument();
  fireEvent.change(inputs[0], { target: { value: '10' } });
  expect(inputs[0]).toHaveValue(5);
  fireEvent.click(screen.getByRole('button', { name: 'Approve & Assign' }));
  await waitFor(() => expect(transfersAPI.verify).toHaveBeenCalledWith(7, {
    from_location_id: 2, to_location_id: 1,
    lines: [{ transfer_detail_id: 8, quantity_approved: 5 }, { transfer_detail_id: 9, quantity_approved: 0 }],
  }));
});

test('recalculates limits when the analyst changes source', async () => {
  render(<VerifyPanel transfer={transfer} locations={locations} />);
  await chooseSource();
  expect(screen.getAllByRole('spinbutton')[0]).toHaveValue(5);
  await chooseSource('3');
  expect(screen.getAllByRole('spinbutton')[0]).toHaveValue(10);
});

test('blocks approval while availability is loading or fails', async () => {
  transfersAPI.sourceAvailability.mockRejectedValue(new Error('Offline'));
  render(<VerifyPanel transfer={transfer} locations={locations} />);
  expect(screen.getByRole('button', { name: 'Approve & Assign' })).toBeDisabled();
  await screen.findByText('Availability unavailable.');
  expect(screen.getByRole('button', { name: 'Approve & Assign' })).toBeDisabled();
});

test('blocks approval when the selected warehouse has no requested stock', async () => {
  transfersAPI.sourceAvailability.mockResolvedValue({ data: {} });
  render(<VerifyPanel transfer={transfer} locations={locations} />);
  await chooseSource();
  screen.getAllByRole('spinbutton').forEach((input) => expect(input).toHaveValue(0));
  expect(screen.getByRole('button', { name: 'Approve & Assign' })).toBeDisabled();
  expect(transfersAPI.verify).not.toHaveBeenCalled();
});

test('package entry cannot exceed the available piece total', async () => {
  const packaged = { ...transfer, details: [{ ...transfer.details[0], quantity_requested: 30,
    product: { product_name: 'Copper tube', installation_material_detail: { pieces_per_package: 10, package_unit: 'Box' } },
  }] };
  transfersAPI.sourceAvailability.mockResolvedValue({ data: { 2: { 5: { available: 15 } } } });
  render(<VerifyPanel transfer={packaged} locations={locations} />);
  await chooseSource();
  const inputs = screen.getAllByRole('spinbutton');
  expect(inputs[0]).toHaveValue(1);
  expect(inputs[1]).toHaveValue(5);
  fireEvent.change(inputs[0], { target: { value: '3' } });
  expect(inputs[0]).toHaveValue(1);
  expect(inputs[1]).toHaveValue(5);
});

test('duplicate product lines share a single stock pool', async () => {
  const duplicate = { ...transfer, details: [transfer.details[0], { ...transfer.details[0], transfer_detail_id: 9 }] };
  render(<VerifyPanel transfer={duplicate} locations={locations} />);
  await chooseSource();
  const inputs = screen.getAllByRole('spinbutton');
  expect(inputs[0]).toHaveValue(5);
  expect(inputs[1]).toHaveValue(0);
  fireEvent.change(inputs[1], { target: { value: '5' } });
  expect(inputs[1]).toHaveValue(0);
});
