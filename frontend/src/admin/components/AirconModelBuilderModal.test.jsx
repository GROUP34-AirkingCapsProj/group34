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
  // getAllBy, not getBy: a model with two lines renders two product <select>s
  // and therefore two copies of every option.
  await waitFor(() =>
    expect(screen.getAllByRole('option', { name: /Indoor Unit A/ }).length).toBeGreaterThan(0)
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

/**
 * Laravel preserves rule order and validatePayload() declares the header rules
 * before the line rules, so "first key wins" would bury the composition error
 * behind a header one. The header errors have an inline home beside their own
 * input; the lines error does not, so it is the one the toast owes the user.
 */
test('a 422 naming both a header field and the lines prefers the lines message', async () => {
  await renderModal();

  const err = new Error('The given data was invalid.');
  err.errors = {
    model_name: ['The model name field is required.'],
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
  expect(toast.error).toHaveBeenCalledWith(
    'A two-piece model needs at least one indoor unit and one outdoor unit. Missing: outdoor.'
  );
  expect(toast.error).not.toHaveBeenCalledWith('The model name field is required.');
});

/**
 * The server deliberately supports models whose member products were archived
 * after composition: AirconModelUnit::product() uses withTrashed() and the
 * composer reads unit_role straight off appliance_details. productsAPI.getAll
 * is under the SoftDeletes scope, so that product is NOT in the loaded list —
 * reading the role from the product list alone would make the model
 * permanently uneditable through the UI.
 */
test('an archived member product does not block an otherwise valid two-piece save', async () => {
  const model = {
    aircon_model_id: 77,
    model_name: 'Split 1.5HP',
    brand_id: 20,
    ac_type_id: 1,
    capacity_rating: '1.5HP',
    variant: 'Inverter',
    is_two_piece: true,
    is_active: true,
    units: [
      // Archived after composition: absent from productsAPI.getAll, but the
      // model row still carries its role and price.
      { aircon_model_unit_id: 1, product_id: 999, product_name: 'Retired Indoor Unit', unit_price: 9000, unit_role: 'indoor', quantity: 1, sort_order: 0, is_archived: true },
      { aircon_model_unit_id: 2, product_id: 200, product_name: 'Outdoor Unit A', unit_price: 15000, unit_role: 'outdoor', quantity: 1, sort_order: 1, is_archived: false },
    ],
  };
  await renderModal({ initialModel: model });

  await userEvent.click(screen.getByRole('button', { name: /update model/i }));

  await waitFor(() => expect(airconModelsAPI.update).toHaveBeenCalled());
  expect(toast.error).not.toHaveBeenCalled();
  expect(airconModelsAPI.update.mock.calls[0][1].lines).toEqual([
    { product_id: 999, quantity: 1 },
    { product_id: 200, quantity: 1 },
  ]);
});

/** Same gap, price side: the running total must not silently drop the archived line. */
test('an archived member product still counts toward the running total', async () => {
  const model = {
    aircon_model_id: 78,
    model_name: 'Split 1.5HP',
    is_two_piece: true,
    is_active: true,
    units: [
      { aircon_model_unit_id: 1, product_id: 999, product_name: 'Retired Indoor Unit', unit_price: 9000, unit_role: 'indoor', quantity: 2, sort_order: 0, is_archived: true },
      { aircon_model_unit_id: 2, product_id: 200, product_name: 'Outdoor Unit A', unit_price: 15000, unit_role: 'outdoor', quantity: 1, sort_order: 1, is_archived: false },
    ],
  };
  await renderModal({ initialModel: model });

  // 2 x 9,000 + 1 x 15,000
  expect(screen.getByText('₱33,000.00')).toBeInTheDocument();
});

test('the product picker offers appliances only, labelled with their unit role', async () => {
  await renderModal();

  expect(screen.getAllByRole('option', { name: /Indoor Unit A — indoor/ }).length).toBeGreaterThan(0);
  expect(screen.queryByRole('option', { name: /Copper Pipe/ })).not.toBeInTheDocument();
});
