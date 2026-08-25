import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AirconModelBuilderModal from './AirconModelBuilderModal';
import { productsAPI, airconModelsAPI, acTypesAPI, capacitiesAPI, variantsAPI } from '../services/api';
import { toast } from '../utils/toast';

/**
 * Covers the two rules the builder owns on the client side: the AC-type
 * `is_two_piece` defaulting (which deliberately discards a manual toggle) and
 * the piece-count guard that mirrors AirconModelComposer, plus the fact that a
 * server 422 surfaces the server's own wording.
 */
jest.mock('../services/api', () => ({
  productsAPI: { getAll: jest.fn() },
  airconModelsAPI: { create: jest.fn(), update: jest.fn() },
  acTypesAPI: { getAll: jest.fn() },
  capacitiesAPI: { getAll: jest.fn() },
  variantsAPI: { getAll: jest.fn() },
}));

// Goes through sweetalert2, which has no place in a unit test.
jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
}));

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ user: { role: 'admin' }, can: () => true }),
}));

const AC_TYPES = [
  { ac_type_id: 1, ac_type_name: 'Split Type', is_two_piece: true },
  { ac_type_id: 2, ac_type_name: 'Window Type', is_two_piece: false },
];

const PRODUCTS = [
  { product_id: 100, product_name: 'Indoor Unit A', product_code: 'IN-A', product_type: 'appliance', unit_role: 'indoor', unit_price: 10000 },
  { product_id: 200, product_name: 'Outdoor Unit A', product_code: 'OUT-A', product_type: 'appliance', unit_role: 'outdoor', unit_price: 15000 },
  { product_id: 300, product_name: 'Window Unit A', product_code: 'WIN-A', product_type: 'appliance', unit_role: 'complete', unit_price: 22000 },
  { product_id: 400, product_name: 'Copper Pipe', product_code: 'CU-1', product_type: 'installation_material', unit_price: 500 },
];

const BRANDS = [{ brand_id: 20, brand_name: 'Airking' }];

// CRA's Jest config uses resetMocks: true, which clears any implementation set
// at module-definition time before each test -- so configure the mocks here.
const setupMocks = () => {
  productsAPI.getAll.mockResolvedValue({ data: PRODUCTS });
  acTypesAPI.getAll.mockResolvedValue({ data: AC_TYPES });
  capacitiesAPI.getAll.mockResolvedValue({ data: [{ capacity_id: 5, capacity_value: '1.5HP' }] });
  variantsAPI.getAll.mockResolvedValue({ data: [{ variant_id: 7, variant_name: 'Inverter' }] });
  airconModelsAPI.create.mockResolvedValue({ data: {} });
  airconModelsAPI.update.mockResolvedValue({ data: {} });
};

const renderModal = async (props = {}) => {
  setupMocks();
  const utils = render(
    <AirconModelBuilderModal
      isOpen
      onClose={() => {}}
      initialModel={null}
      brands={BRANDS}
      onSaved={() => {}}
      canSubmit
      {...props}
    />
  );
  // Wait for the AC types and the product list to land.
  await waitFor(() => expect(screen.getByRole('option', { name: 'Split Type' })).toBeInTheDocument());
  await waitFor(() =>
    expect(screen.getByRole('option', { name: /Indoor Unit A/ })).toBeInTheDocument()
  );
  return utils;
};

const acTypeSelect = () => screen.getByLabelText(/ac type/i);
const twoPieceCheckbox = () => screen.getByLabelText(/two-piece/i);
const productSelect = (n) => screen.getByLabelText(new RegExp(`unit ${n} product`, 'i'));
const quantityInput = (n) => screen.getByLabelText(new RegExp(`unit ${n} quantity`, 'i'));
const submitButton = () => screen.getByRole('button', { name: /create model/i });

test('choosing a one-piece AC type unticks the two-piece checkbox', async () => {
  await renderModal();
  expect(twoPieceCheckbox()).toBeChecked();

  await userEvent.selectOptions(acTypeSelect(), '2');

  expect(twoPieceCheckbox()).not.toBeChecked();
});

test('choosing a two-piece AC type ticks the two-piece checkbox', async () => {
  await renderModal();

  // Routed through the one-piece pick first: asserting "checked" straight from
  // a fresh form would also pass against a handler that did nothing at all,
  // since the initial state is already checked.
  await userEvent.selectOptions(acTypeSelect(), '2');
  expect(twoPieceCheckbox()).not.toBeChecked();

  await userEvent.selectOptions(acTypeSelect(), '1');

  expect(twoPieceCheckbox()).toBeChecked();
});

/**
 * The defaulting rule deliberately throws away a manual toggle when the type
 * changes: keeping it would leave a model marked one-piece under Split Type,
 * which the composer would reject on save anyway.
 */
test('a manual two-piece toggle is discarded when the AC type then changes', async () => {
  await renderModal();

  await userEvent.selectOptions(acTypeSelect(), '2');
  expect(twoPieceCheckbox()).not.toBeChecked();

  // Manual override: user ticks two-piece back on under a one-piece type.
  await userEvent.click(twoPieceCheckbox());
  expect(twoPieceCheckbox()).toBeChecked();

  // Re-picking the same one-piece type must re-apply its default, not keep
  // the override.
  await userEvent.selectOptions(acTypeSelect(), '1');
  await userEvent.selectOptions(acTypeSelect(), '2');

  expect(twoPieceCheckbox()).not.toBeChecked();
});

test('a two-piece model with only an indoor unit is blocked before the API is called', async () => {
  await renderModal();

  await userEvent.type(screen.getByLabelText(/model name/i), 'Split 1.5HP');
  await userEvent.selectOptions(acTypeSelect(), '1');
  expect(twoPieceCheckbox()).toBeChecked();
  await userEvent.selectOptions(productSelect(1), '100');

  await userEvent.click(submitButton());

  expect(toast.error).toHaveBeenCalledWith(
    'A two-piece aircon model needs at least one indoor and one outdoor unit.'
  );
  expect(airconModelsAPI.create).not.toHaveBeenCalled();
});

test('a valid two-piece model submits both product ids with their quantities', async () => {
  await renderModal();

  await userEvent.type(screen.getByLabelText(/model name/i), 'Split 1.5HP');
  await userEvent.selectOptions(acTypeSelect(), '1');
  await userEvent.selectOptions(productSelect(1), '100');
  await userEvent.click(screen.getByRole('button', { name: /add unit/i }));
  await userEvent.selectOptions(productSelect(2), '200');
  await userEvent.clear(quantityInput(2));
  await userEvent.type(quantityInput(2), '2');

  await userEvent.click(submitButton());

  await waitFor(() => expect(airconModelsAPI.create).toHaveBeenCalled());
  const payload = airconModelsAPI.create.mock.calls[0][0];
  expect(payload.model_name).toBe('Split 1.5HP');
  expect(payload.is_two_piece).toBe(true);
  expect(payload.lines).toEqual([
    { product_id: 100, quantity: 1 },
    { product_id: 200, quantity: 2 },
  ]);
});

/**
 * The composer's 422 messages are already written for a human; a generic
 * "Failed to save" would throw away the only thing that tells the user what to
 * fix.
 */
test('a 422 from the server surfaces the server message', async () => {
  await renderModal();

  const err = new Error('The given data was invalid.');
  err.errors = {
    lines: ['A two-piece model needs at least one indoor unit and one outdoor unit. Missing: outdoor.'],
  };
  airconModelsAPI.create.mockRejectedValue(err);

  await userEvent.type(screen.getByLabelText(/model name/i), 'Split 1.5HP');
  await userEvent.selectOptions(acTypeSelect(), '1');
  await userEvent.selectOptions(productSelect(1), '100');
  await userEvent.click(screen.getByRole('button', { name: /add unit/i }));
  await userEvent.selectOptions(productSelect(2), '200');

  await userEvent.click(submitButton());

  await waitFor(() => expect(airconModelsAPI.create).toHaveBeenCalled());
  await waitFor(() =>
    expect(toast.error).toHaveBeenCalledWith(
      'A two-piece model needs at least one indoor unit and one outdoor unit. Missing: outdoor.'
    )
  );
});

test('a duplicate-product 422 surfaces the lines.0.product_id message', async () => {
  await renderModal();

  const err = new Error('The given data was invalid.');
  err.errors = {
    'lines.0.product_id': ['Each product can appear only once — raise its quantity instead of adding a second line.'],
  };
  airconModelsAPI.create.mockRejectedValue(err);

  await userEvent.type(screen.getByLabelText(/model name/i), 'Split 1.5HP');
  await userEvent.selectOptions(acTypeSelect(), '1');
  await userEvent.selectOptions(productSelect(1), '100');
  await userEvent.click(screen.getByRole('button', { name: /add unit/i }));
  await userEvent.selectOptions(productSelect(2), '200');

  await userEvent.click(submitButton());

  await waitFor(() =>
    expect(toast.error).toHaveBeenCalledWith(
      'Each product can appear only once — raise its quantity instead of adding a second line.'
    )
  );
});

test('the product picker offers appliances only, labelled with their unit role', async () => {
  await renderModal();

  expect(screen.getAllByRole('option', { name: /Indoor Unit A — indoor/ }).length).toBeGreaterThan(0);
  expect(screen.queryByRole('option', { name: /Copper Pipe/ })).not.toBeInTheDocument();
});
