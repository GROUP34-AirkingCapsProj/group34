import { render, screen, within } from '@testing-library/react';
import ReceivingReceipt from './ReceivingReceipt';
import { companySettingsAPI } from '../services/api';

/**
 * Covers the split-aircon grouping the GRN table renders from the detail
 * endpoint's `aircon_groups` key: a header per tagged model, the imbalance
 * warning on a short delivery, and — the case that matters most — the fact that
 * an untagged receiving (every document that predates the feature) still
 * renders one plain row per detail with every column intact.
 */
jest.mock('../services/api', () => ({
  companySettingsAPI: { get: jest.fn() },
  openApiFile: jest.fn(),
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
}));

// CRA's Jest config uses resetMocks: true, which clears any implementation set
// at module-definition time before each test -- so configure the mocks here.
const setupMocks = () => {
  companySettingsAPI.get.mockResolvedValue({ company_name: 'Airking Air Conditioning' });
};

const PO = {
  po_number: 'PO-001',
  details: [
    { po_detail_id: 1, product_id: 100, quantity_ordered: 3, unit_price: 10000 },
    { po_detail_id: 2, product_id: 200, quantity_ordered: 3, unit_price: 15000 },
    { po_detail_id: 3, product_id: 400, quantity_ordered: 10, unit_price: 500 },
  ],
};

const INDOOR = {
  receiving_detail_id: 11,
  product_id: 100,
  quantity_amount: 3,
  condition: 'Good',
  product: { product_name: 'Indoor Unit A', product_code: 'IN-A', product_type: 'appliance' },
};
const OUTDOOR = {
  receiving_detail_id: 12,
  product_id: 200,
  quantity_amount: 3,
  condition: 'Good',
  product: { product_name: 'Outdoor Unit A', product_code: 'OUT-A', product_type: 'appliance' },
};
const PIPE = {
  receiving_detail_id: 13,
  product_id: 400,
  quantity_amount: 10,
  condition: 'Damaged',
  product: { product_name: 'Copper Pipe', product_code: 'CU-1', product_type: 'appliance' },
};

const receiving = (details, airconGroups) => ({
  receiving_id: 7,
  receiving_number: 'GRN-007',
  receiving_date: '2026-08-20',
  total_quantity_received: details.reduce((s, d) => s + d.quantity_amount, 0),
  purchaseOrder: PO,
  details,
  ...(airconGroups === undefined ? {} : { aircon_groups: airconGroups }),
});

const balancedGroup = {
  aircon_model_id: 12,
  model_name: 'TCL 2HP Split',
  complete_count: 3,
  is_unbalanced: false,
  unpaired: {},
  // Deliberately in the opposite order to `details`: groups are matched back to
  // the rendered rows by row id, never by array position.
  lines: [
    { receiving_detail_id: 12, product_id: 200, quantity: 3, aircon_model_id: 12 },
    { receiving_detail_id: 11, product_id: 100, quantity: 3, aircon_model_id: 12 },
  ],
};

const renderReceipt = async (rcv) => {
  setupMocks();
  const utils = render(<ReceivingReceipt receiving={rcv} isOpen onClose={() => {}} />);
  // Let the company-settings fetch settle before asserting, so the table is read
  // in its final state and React raises no act() warning.
  await screen.findByText('Airking Air Conditioning');
  return utils;
};

const rowFor = (name) => screen.getByText(name).closest('tr');
const cellsOf = (row) => within(row).getAllByRole('cell').map((c) => c.textContent);

test('an untagged receiving renders one plain row per detail with every column', async () => {
  await renderReceipt(receiving([INDOOR, OUTDOOR, PIPE], []));

  // Product / Ordered / Received / Unit Cost / Amount / Condition
  expect(cellsOf(rowFor('Indoor Unit A'))).toEqual([
    'Indoor Unit A · IN-A', '3', '3', '₱10,000.00', '₱30,000.00', 'Good',
  ]);
  expect(cellsOf(rowFor('Outdoor Unit A'))).toEqual([
    'Outdoor Unit A · OUT-A', '3', '3', '₱15,000.00', '₱45,000.00', 'Good',
  ]);
  expect(cellsOf(rowFor('Copper Pipe'))).toEqual([
    'Copper Pipe · CU-1', '10', '10', '₱500.00', '₱5,000.00', 'Damaged',
  ]);

  // No grouping furniture at all on the untagged path.
  expect(screen.queryByText(/complete$/)).not.toBeInTheDocument();
  expect(screen.queryByText(/unpaired/)).not.toBeInTheDocument();
});

test('a receiving with no aircon_groups key at all renders exactly as the untagged one', async () => {
  await renderReceipt(receiving([INDOOR, OUTDOOR], undefined));

  expect(cellsOf(rowFor('Indoor Unit A'))).toEqual([
    'Indoor Unit A · IN-A', '3', '3', '₱10,000.00', '₱30,000.00', 'Good',
  ]);
  expect(screen.queryByText(/complete$/)).not.toBeInTheDocument();
});

test('a balanced group renders its model header above both member rows', async () => {
  await renderReceipt(receiving([INDOOR, OUTDOOR], [balancedGroup]));

  expect(screen.getByText('TCL 2HP Split · 3 complete')).toBeInTheDocument();
  expect(screen.queryByText(/unpaired/)).not.toBeInTheDocument();

  // The member rows keep every value they had before grouping.
  expect(cellsOf(rowFor('Indoor Unit A'))).toEqual([
    'Indoor Unit A · IN-A', '3', '3', '₱10,000.00', '₱30,000.00', 'Good',
  ]);
  expect(cellsOf(rowFor('Outdoor Unit A'))).toEqual([
    'Outdoor Unit A · OUT-A', '3', '3', '₱15,000.00', '₱45,000.00', 'Good',
  ]);

  // Header first, then its two members, contiguously.
  const rows = screen.getAllByRole('row').slice(1); // drop the <thead> row
  expect(rows[0]).toHaveTextContent('TCL 2HP Split · 3 complete');
  expect(rows[1]).toHaveTextContent('Indoor Unit A');
  expect(rows[2]).toHaveTextContent('Outdoor Unit A');
});

test('a short delivery renders the imbalance warning naming the stranded roles', async () => {
  const shortOutdoor = { ...OUTDOOR, quantity_amount: 2 };
  await renderReceipt(receiving([INDOOR, shortOutdoor], [{
    ...balancedGroup,
    complete_count: 2,
    is_unbalanced: true,
    unpaired: { indoor: 1 },
    lines: [
      { receiving_detail_id: 11, product_id: 100, quantity: 3, aircon_model_id: 12 },
      { receiving_detail_id: 12, product_id: 200, quantity: 2, aircon_model_id: 12 },
    ],
  }]));

  const header = screen.getByText(/TCL 2HP Split/).closest('tr');
  expect(header).toHaveTextContent('TCL 2HP Split · 2 complete');
  expect(header).toHaveTextContent('1 indoor unpaired');

  // Still a short delivery, not a dropped row: the outdoor line is intact.
  expect(cellsOf(rowFor('Outdoor Unit A'))).toEqual([
    'Outdoor Unit A · OUT-A', '3', '2', '₱15,000.00', '₱30,000.00', 'Good',
  ]);
});

test('several stranded roles are all named on the warning', async () => {
  await renderReceipt(receiving([INDOOR, OUTDOOR], [{
    ...balancedGroup,
    complete_count: 1,
    is_unbalanced: true,
    unpaired: { indoor: 2, outdoor: 1 },
  }]));

  expect(screen.getByText(/unpaired/)).toHaveTextContent('2 indoor, 1 outdoor unpaired');
});

test('a loose line beside a tagged group renders with no header of its own', async () => {
  await renderReceipt(receiving([INDOOR, OUTDOOR, PIPE], [
    balancedGroup,
    // The grouper emits ungrouped lines as their own null-model group.
    {
      aircon_model_id: null,
      model_name: null,
      complete_count: null,
      is_unbalanced: false,
      unpaired: {},
      lines: [{ receiving_detail_id: 13, product_id: 400, quantity: 10, aircon_model_id: null }],
    },
  ]));

  const rows = screen.getAllByRole('row').slice(1);
  expect(rows).toHaveLength(4); // one header + two members + the loose line
  expect(rows[3]).toHaveTextContent('Copper Pipe');
  expect(cellsOf(rows[3])).toEqual([
    'Copper Pipe · CU-1', '10', '10', '₱500.00', '₱5,000.00', 'Damaged',
  ]);
  // Only one header in the whole table.
  expect(screen.getAllByText(/complete$/)).toHaveLength(1);
});
