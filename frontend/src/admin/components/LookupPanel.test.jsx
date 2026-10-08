import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import LookupPanel from './LookupPanel';
import { LOOKUP_CONFIGS } from '../config/lookupConfigs';
import { acTypesAPI } from '../services/api';

/**
 * Covers LookupPanel's `checkbox` field type and `bool` column format, first
 * used by the acTypes config.
 *
 * These tests deliberately drive the REAL LOOKUP_CONFIGS.acTypes entry rather
 * than a hand-built fixture: the behaviour at risk is the interaction between
 * that config's `default: true` and the panel's form-building, so a fixture
 * that restated `default: true` locally would keep passing if the shipped
 * config ever lost it.
 */
jest.mock('../services/api', () => {
  const lookupApi = () => ({
    getAll: jest.fn(),
    getArchived: jest.fn(),
    create: jest.fn(),
    update: jest.fn(),
    delete: jest.fn(),
    restore: jest.fn(),
    forceDelete: jest.fn(),
  });
  return {
    acTypesAPI: lookupApi(),
    categoriesAPI: lookupApi(),
    brandsAPI: lookupApi(),
    unitsAPI: lookupApi(),
    capacitiesAPI: lookupApi(),
    variantsAPI: lookupApi(),
  };
});

// Both go through sweetalert2, which has no place in a unit test.
jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn() },
}));
jest.mock('../utils/confirm', () => ({
  confirmAction: jest.fn().mockResolvedValue(true),
}));

const config = LOOKUP_CONFIGS.acTypes;
const permissions = { canCreate: true, canUpdate: true, canArchive: true };

const row = (overrides) => ({
  ac_type_id: 1,
  ac_type_name: 'Split Type',
  is_two_piece: true,
  usage_count: 0,
  ...overrides,
});

// CRA's Jest config uses resetMocks: true, which clears any implementation set
// at module-definition time before each test -- so configure the mocks here.
const renderPanel = async (rows = []) => {
  acTypesAPI.getAll.mockResolvedValue({ data: rows });
  acTypesAPI.getArchived.mockResolvedValue({ data: [] });
  acTypesAPI.create.mockResolvedValue({ data: row() });
  const utils = render(<LookupPanel config={config} permissions={permissions} />);
  await waitFor(() => expect(acTypesAPI.getAll).toHaveBeenCalled());
  return utils;
};

const openAddForm = async () => {
  await userEvent.click(screen.getByRole('button', { name: /add ac type/i }));
  return screen.getByRole('dialog');
};

const twoPieceBox = (dialog) =>
  within(dialog).getByLabelText(/two-piece \(indoor \+ outdoor\)/i);

/**
 * The regression this file exists for. buildEmptyForm's `checkbox` branch used
 * to short-circuit ahead of its `default` branch, so a checkbox field's
 * `default: true` was silently dropped and the Add form opened unticked --
 * meaning every AC type added through the UI would have been recorded as
 * one-piece unless the admin happened to notice and tick the box. Split,
 * cassette, ducted, floor-standing and ceiling-mounted units are all
 * two-piece, so the default is the common case, not an edge case.
 */
test('a fresh Add form honours the checkbox field default', async () => {
  await renderPanel();
  const dialog = await openAddForm();

  expect(twoPieceBox(dialog)).toBeChecked();
});

test('editing a row reflects that row rather than the default', async () => {
  await renderPanel([row({ ac_type_name: 'Portable', is_two_piece: false })]);

  await userEvent.click(screen.getByRole('button', { name: /edit/i }));
  const dialog = screen.getByRole('dialog');

  expect(twoPieceBox(dialog)).not.toBeChecked();
});

describe("the 'bool' column format", () => {
  const twoPieceCell = () => {
    const index = config.columns.findIndex((c) => c.key === 'is_two_piece');
    const bodyRow = screen.getAllByRole('row')[1];
    return within(bodyRow).getAllByRole('cell')[index];
  };

  /**
   * `false` is the trap here. renderCell short-circuits blank values to an em
   * dash before reaching the format switch, and that guard MUST stay strictly
   * equal -- under loose equality `false == ''` is true, so every one-piece
   * type would render as "--" (missing) instead of "No" (known, and false).
   */
  test('renders a false value as No, not blank and not an em dash', async () => {
    await renderPanel([row({ ac_type_name: 'Portable', is_two_piece: false })]);

    const cell = twoPieceCell();
    expect(cell).toHaveTextContent(/^No$/);
  });

  test('renders a true value as Yes', async () => {
    await renderPanel([row({ is_two_piece: true })]);

    expect(twoPieceCell()).toHaveTextContent(/^Yes$/);
  });

  /**
   * MySQL tinyint survives JSON as integer 0/1, so the panel sees ints rather
   * than booleans unless a model cast intervenes. Both must render.
   */
  test('renders integer 0 and 1 the same way as booleans', async () => {
    await renderPanel([row({ is_two_piece: 0 })]);
    expect(twoPieceCell()).toHaveTextContent(/^No$/);
  });
});

describe('submitting the form', () => {
  test('sends a real boolean false when the box is unticked', async () => {
    await renderPanel();
    const dialog = await openAddForm();

    await userEvent.type(
      within(dialog).getByRole('textbox'),
      'Portable Test'
    );
    await userEvent.click(twoPieceBox(dialog));
    await userEvent.click(within(dialog).getByRole('button', { name: /^add ac type$/i }));

    await waitFor(() => expect(acTypesAPI.create).toHaveBeenCalled());

    // toEqual is strict on primitives, so this pins the type as well as the
    // value: a falsy stand-in ('' or 0, either of which Laravel's `boolean`
    // rule would treat differently from false) fails this assertion, and the
    // exact-shape match also catches an extra or renamed key.
    expect(acTypesAPI.create.mock.calls[0][0]).toEqual({
      ac_type_name: 'Portable Test',
      is_two_piece: false,
    });
  });

  test('sends a real boolean true when the box is left at its default', async () => {
    await renderPanel();
    const dialog = await openAddForm();

    await userEvent.type(within(dialog).getByRole('textbox'), 'Cassette Test');
    await userEvent.click(within(dialog).getByRole('button', { name: /^add ac type$/i }));

    await waitFor(() => expect(acTypesAPI.create).toHaveBeenCalled());

    const payload = acTypesAPI.create.mock.calls[0][0];
    expect(payload.is_two_piece).toBe(true);
  });
});
