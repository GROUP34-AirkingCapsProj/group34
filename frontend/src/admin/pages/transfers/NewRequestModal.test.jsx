import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import NewRequestModal from './NewRequestModal';
import { transfersAPI, locationsAPI, itemsAPI } from '../../services/api';

jest.mock('../../services/api', () => ({ transfersAPI: { demandAvailability: jest.fn(), create: jest.fn(), updateRequest: jest.fn(), saveDraft: jest.fn() }, locationsAPI: { getAll: jest.fn() }, itemsAPI: { getAll: jest.fn() } }));
jest.mock('../../context/AuthContext', () => ({ useAuth: () => ({ user: { role: { role_name: 'inventory_analyst' } } }), useUserLocation: () => ({ locationId: 1 }) }));
jest.mock('../../components/Modal', () => ({ children }) => <div>{children}</div>);
jest.mock('../../components/ProductSearchSelect', () => ({ value, onChange }) => <select aria-label="Product" value={value} onChange={(e) => onChange(e.target.value)}><option value="">Choose</option><option value="5">TCL</option></select>);
jest.mock('../../utils/toast', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

beforeEach(() => {
  localStorage.clear();
  transfersAPI.saveDraft.mockImplementation(async (id) => ({ data: { transfer_id: id || 99 } }));
  locationsAPI.getAll.mockResolvedValue({ data: [{ location_id: 1, location_name: 'A', location_type: 'warehouse' }, { location_id: 2, location_name: 'B', location_type: 'warehouse' }] });
  itemsAPI.getAll.mockResolvedValue({ data: [{ product_id: 5, product_name: 'TCL', product_type: 'appliance' }] });
  transfersAPI.demandAvailability.mockResolvedValue({ data: { 2: { 5: { available: 5 } } } });
  transfersAPI.create.mockResolvedValue({ data: { transfer_number: 'TR-1' } });
});

test('IA-demand displays stock and caps submission to the source availability', async () => {
  render(<NewRequestModal isOpen />);
  await screen.findAllByRole('option', { name: 'B' });
  expect(screen.queryByRole('button', { name: 'IA-demand' })).not.toBeInTheDocument();
  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: '2' } });
  fireEvent.change(screen.getByLabelText('Product'), { target: { value: '5' } });
  await screen.findByText('5 available');
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '10' } });
  expect(screen.getByRole('spinbutton')).toHaveValue(5);
  fireEvent.click(screen.getByRole('button', { name: 'Submit Request' }));
  await waitFor(() => expect(transfersAPI.create).toHaveBeenCalledWith(expect.objectContaining({ intent: 'demand', products: [{ product_id: 5, quantity_requested: 5 }] })));
});

test('IA-demand cannot submit with no source stock', async () => {
  transfersAPI.demandAvailability.mockResolvedValue({ data: {} });
  render(<NewRequestModal isOpen />);
  await screen.findAllByRole('option', { name: 'B' });
  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: '2' } });
  fireEvent.change(screen.getByLabelText('Product'), { target: { value: '5' } });
  await screen.findByText('0 available');
  await waitFor(() => expect(screen.getByRole('spinbutton')).toHaveValue(0));
  expect(screen.getByRole('button', { name: 'Submit Request' })).toBeDisabled();
});

test('IA-demand package quantities stay within available pieces', async () => {
  itemsAPI.getAll.mockResolvedValue({ data: [{ product_id: 5, product_name: 'TCL', product_type: 'installation_material', pieces_per_package: 10, package_unit: 'Box' }] });
  transfersAPI.demandAvailability.mockResolvedValue({ data: { 2: { 5: { available: 15 } } } });
  render(<NewRequestModal isOpen />);
  await screen.findAllByRole('option', { name: 'B' });
  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: '2' } });
  fireEvent.change(screen.getByLabelText('Product'), { target: { value: '5' } });
  await screen.findByText('15 available');
  fireEvent.change(screen.getAllByRole('spinbutton')[0], { target: { value: '3' } });
  expect(screen.getAllByRole('spinbutton')[0]).toHaveValue(1);
  expect(screen.getAllByRole('spinbutton')[1]).toHaveValue(5);
});

test('a rejected request can be edited and submitted again', async () => {
  transfersAPI.updateRequest.mockResolvedValue({ success: true });
  render(<NewRequestModal isOpen editTransfer={{ transfer_id: 9, transfer_number: 'TR-9', intent: 'pull',
    status: { status_name: 'Rejected' }, to_location_id: 1,
    details: [{ transfer_detail_id: 10, product_id: 5, quantity_requested: 2 }],
  }} />);
  await waitFor(() => expect(screen.getByRole('spinbutton')).toHaveValue(2));
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '4' } });
  fireEvent.click(screen.getByRole('button', { name: 'Submit again' }));
  await waitFor(() => expect(transfersAPI.updateRequest).toHaveBeenCalledWith(9,
    expect.objectContaining({ resubmit: true, products: [{ product_id: 5, quantity_requested: 4 }] })));
});

test('a cancelled IA-demand draft keeps editable demand even when source stock is unavailable', async () => {
  transfersAPI.demandAvailability.mockResolvedValue({ data: {} });
  transfersAPI.updateRequest.mockResolvedValue({ success: true });
  render(<NewRequestModal isOpen editTransfer={{ transfer_id: 9, intent: 'demand',
    status: { status_name: 'Draft' }, from_location_id: 2, to_location_id: 1,
    details: [{ transfer_detail_id: 10, product_id: 5, quantity_requested: 5 }],
  }} />);
  await screen.findByText('0 available');
  expect(screen.getByRole('spinbutton')).toHaveValue(5);
  expect(screen.getByRole('button', { name: 'Submit again' })).toBeDisabled();
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '7' } });
  expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
  await waitFor(() => expect(transfersAPI.saveDraft).toHaveBeenCalledWith(9,
    expect.objectContaining({ form: expect.objectContaining({ lines: [expect.objectContaining({ quantity: '7' })] }) }),
    expect.anything()));
});

test('closing an incomplete form automatically saves a draft without submitting it', async () => {
  const onClose = jest.fn();
  render(<NewRequestModal isOpen onClose={onClose} />);
  await screen.findAllByRole('option', { name: 'B' });
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Waiting for a source warehouse' } });
  fireEvent.click(screen.getByRole('button', { name: 'Close' }));
  await waitFor(() => expect(onClose).toHaveBeenCalled());
  expect(transfersAPI.saveDraft).toHaveBeenCalledWith(null, expect.objectContaining({
    form: expect.objectContaining({ notes: 'Waiting for a source warehouse', fromLocationId: '',
      lines: [expect.objectContaining({ productId: '' })] }),
  }), expect.anything());
  expect(transfersAPI.create).not.toHaveBeenCalled();
});

test('leaving and reopening restores inputs before submission', async () => {
  const first = render(<NewRequestModal isOpen />);
  await screen.findAllByRole('option', { name: 'B' });
  fireEvent.change(screen.getByLabelText('Product'), { target: { value: '5' } });
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '4' } });
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Keep this request' } });
  first.unmount();
  render(<NewRequestModal isOpen />);
  await waitFor(() => expect(screen.getByRole('spinbutton')).toHaveValue(4));
  expect(screen.getByRole('textbox')).toHaveValue('Keep this request');
  expect(screen.getByLabelText('Product')).toHaveValue('5');
});

test('browser exit preserves a backup and sends the draft with keepalive', async () => {
  render(<NewRequestModal isOpen />);
  await screen.findAllByRole('option', { name: 'B' });
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Recover after refresh' } });
  fireEvent(window, new Event('pagehide'));
  await waitFor(() => expect(transfersAPI.saveDraft).toHaveBeenCalledWith(null,
    expect.objectContaining({ form: expect.objectContaining({ notes: 'Recover after refresh' }) }), { keepalive: true }));
  const cached = JSON.parse(localStorage.getItem('transfer-draft:inventory_analyst:new'));
  expect(cached.form.notes).toBe('Recover after refresh');
  expect(transfersAPI.create).not.toHaveBeenCalled();
});

test('submission waits for earlier autosaves and reuses the draft record', async () => {
  let resolveSave;
  transfersAPI.saveDraft.mockImplementationOnce(() => new Promise((resolve) => { resolveSave = resolve; }));
  render(<NewRequestModal isOpen />);
  await screen.findAllByRole('option', { name: 'B' });
  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: '2' } });
  fireEvent.change(screen.getByLabelText('Product'), { target: { value: '5' } });
  await screen.findByText('5 available');
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '2' } });
  await waitFor(() => expect(transfersAPI.saveDraft).toHaveBeenCalled());
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '4' } });
  fireEvent.click(screen.getByRole('button', { name: 'Submit Request' }));
  expect(transfersAPI.create).not.toHaveBeenCalled();
  await act(async () => { resolveSave({ data: { transfer_id: 99 } }); });
  await waitFor(() => expect(transfersAPI.create).toHaveBeenCalledWith(expect.objectContaining({
    draft_id: 99, products: [{ product_id: 5, quantity_requested: 4 }],
  })));
  expect(localStorage.getItem('transfer-draft:inventory_analyst:new')).toBeNull();
});
