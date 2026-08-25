import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ProductRegisterModal from './ProductRegisterModal';
import {
  batchAPI,
  locationsAPI,
  consumableSupplyAPI,
  pendingProductsAPI,
  acTypesAPI,
  categoriesAPI,
  brandsAPI,
  unitsAPI,
  capacitiesAPI,
  variantsAPI,
} from '../services/api';

/**
 * Covers the AC Type / Unit Role pair on the appliance registration form:
 * the one-piece defaulting rule and the fact that both fields are always
 * submitted together.
 */
jest.mock('../services/api', () => ({
  batchAPI: { get: jest.fn() },
  locationsAPI: { getAll: jest.fn() },
  consumableSupplyAPI: { getSupplyTypes: jest.fn(), getCatalog: jest.fn() },
  pendingProductsAPI: { store: jest.fn() },
  categoriesAPI: { getAll: jest.fn() },
  brandsAPI: { getAll: jest.fn() },
  unitsAPI: { getAll: jest.fn() },
  capacitiesAPI: { getAll: jest.fn() },
  variantsAPI: { getAll: jest.fn() },
  acTypesAPI: { getAll: jest.fn() },
}));

// Goes through sweetalert2, which has no place in a unit test.
jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
}));

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ user: { role: 'admin' } }),
}));

const AC_TYPES = [
  { ac_type_id: 1, ac_type_name: 'Split Type', is_two_piece: true },
  { ac_type_id: 2, ac_type_name: 'Window Type', is_two_piece: false },
];

// CRA's Jest config uses resetMocks: true, which clears any implementation set
// at module-definition time before each test -- so configure the mocks here.
const setupMocks = () => {
  batchAPI.get.mockResolvedValue({
    data: {
      categories: { data: [{ category_id: 10, category_name: 'Aircon' }] },
      brands: { data: [{ brand_id: 20, brand_name: 'Airking' }] },
      units: { data: [{ unit_id: 30, unit_name: 'Piece' }] },
      capacities: { data: [] },
      variants: { data: [] },
      acTypes: { data: AC_TYPES },
    },
  });
  locationsAPI.getAll.mockResolvedValue({ data: [{ location_id: 40, location_name: 'Main Warehouse' }] });
  consumableSupplyAPI.getSupplyTypes.mockResolvedValue({ data: ['Refrigerant'] });
  consumableSupplyAPI.getCatalog.mockResolvedValue({ data: null });
  pendingProductsAPI.store.mockResolvedValue({ data: {} });
};

/** Renders the modal and switches to the Appliance tab. */
const openApplianceTab = async () => {
  const utils = render(<ProductRegisterModal isOpen onClose={() => {}} />);
  await userEvent.click(await screen.findByRole('button', { name: /^appliance$/i }));
  return utils;
};

const renderModal = async () => {
  setupMocks();
  return openApplianceTab();
};

const acTypeSelect = () => screen.getByLabelText(/ac type/i);
const unitRoleSelect = () => screen.getByLabelText(/unit role/i);

// The pre-existing appliance selects have bare <label>s with no htmlFor, so
// they can't be reached by label text; pick each one via an option only it has.
const chooseByOption = async (optionName, value) => {
  const option = screen.getByRole('option', { name: optionName });
  await userEvent.selectOptions(option.closest('select'), value);
};

const fillRequiredAppliance = async (barcode, name) => {
  await userEvent.type(screen.getAllByRole('textbox')[0], barcode);
  await userEvent.type(screen.getAllByRole('textbox')[1], name);
  await chooseByOption('Aircon', '10');
  await chooseByOption('Airking', '20');
  await chooseByOption('Piece', '30');
  await chooseByOption('Main Warehouse', '40');
};

test('the AC Type dropdown is populated from the batch response', async () => {
  await renderModal();

  await waitFor(() =>
    expect(screen.getByRole('option', { name: 'Split Type' })).toBeInTheDocument()
  );
  expect(screen.getByRole('option', { name: 'Window Type' })).toBeInTheDocument();
});

/**
 * The per-lookup fallback runs exactly when the batch endpoint is down, i.e.
 * when nobody is watching; without acTypesAPI wired in there the dropdown is
 * silently empty.
 */
test('the AC Type dropdown falls back to the per-lookup fetch when batch fails', async () => {
  setupMocks();
  batchAPI.get.mockRejectedValue(new Error('batch down'));
  acTypesAPI.getAll.mockResolvedValue({ data: AC_TYPES });
  categoriesAPI.getAll.mockResolvedValue({ data: [] });
  brandsAPI.getAll.mockResolvedValue({ data: [] });
  unitsAPI.getAll.mockResolvedValue({ data: [] });
  capacitiesAPI.getAll.mockResolvedValue({ data: [] });
  variantsAPI.getAll.mockResolvedValue({ data: [] });

  await openApplianceTab();

  await waitFor(() =>
    expect(screen.getByRole('option', { name: 'Split Type' })).toBeInTheDocument()
  );
});

test('a one-piece kind preselects Unit Role as complete', async () => {
  await renderModal();
  await waitFor(() => expect(screen.getByRole('option', { name: 'Window Type' })).toBeInTheDocument());

  await userEvent.selectOptions(acTypeSelect(), '2');

  expect(unitRoleSelect()).toHaveValue('complete');
});

test('a two-piece kind leaves Unit Role unchosen', async () => {
  await renderModal();
  await waitFor(() => expect(screen.getByRole('option', { name: 'Split Type' })).toBeInTheDocument());

  // Deliberately routed through a one-piece pick first: asserting '' straight
  // from a fresh form would also pass against a handler that did nothing at
  // all, since '' is the initial state.
  await userEvent.selectOptions(acTypeSelect(), '2');
  expect(unitRoleSelect()).toHaveValue('complete');

  await userEvent.selectOptions(acTypeSelect(), '1');

  expect(acTypeSelect()).toHaveValue('1');
  expect(unitRoleSelect()).toHaveValue('');
});

/**
 * The defaulting rule deliberately throws away a manual pick when the type
 * changes: keeping it would leave e.g. 'complete' recorded under Split Type.
 */
test('a manual Unit Role choice is discarded when the AC type then changes', async () => {
  await renderModal();
  await waitFor(() => expect(screen.getByRole('option', { name: 'Split Type' })).toBeInTheDocument());

  await userEvent.selectOptions(acTypeSelect(), '1');
  await userEvent.selectOptions(unitRoleSelect(), 'indoor');
  expect(unitRoleSelect()).toHaveValue('indoor');

  await userEvent.selectOptions(acTypeSelect(), '2');

  expect(unitRoleSelect()).toHaveValue('complete');
});

/**
 * ProductController::update() rebuilds appliance_details with updateOrCreate on
 * a full payload, so an omitted key is nulled out -- both fields must always be
 * present, even when the user picked nothing.
 */
test('submitting sends both ac_type_id and unit_role', async () => {
  await renderModal();
  await waitFor(() => expect(screen.getByRole('option', { name: 'Split Type' })).toBeInTheDocument());

  await fillRequiredAppliance('900001', 'Test Aircon');
  await userEvent.selectOptions(acTypeSelect(), '1');
  await userEvent.selectOptions(unitRoleSelect(), 'outdoor');

  await userEvent.click(screen.getByRole('button', { name: /submit for approval/i }));

  await waitFor(() => expect(pendingProductsAPI.store).toHaveBeenCalled());
  const payload = pendingProductsAPI.store.mock.calls[0][0];
  expect(payload.appliance).toEqual(
    expect.objectContaining({ ac_type_id: 1, unit_role: 'outdoor' })
  );
});

test('submitting with neither field chosen still sends both keys as null', async () => {
  await renderModal();
  await waitFor(() => expect(screen.getByRole('option', { name: 'Split Type' })).toBeInTheDocument());

  await fillRequiredAppliance('900002', 'Bare Aircon');

  await userEvent.click(screen.getByRole('button', { name: /submit for approval/i }));

  await waitFor(() => expect(pendingProductsAPI.store).toHaveBeenCalled());
  const { appliance } = pendingProductsAPI.store.mock.calls[0][0];
  expect(appliance).toHaveProperty('ac_type_id', null);
  expect(appliance).toHaveProperty('unit_role', null);
});
