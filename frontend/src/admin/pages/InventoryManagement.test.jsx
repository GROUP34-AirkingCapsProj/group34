import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import InventoryManagement from './InventoryManagement';
import {
  inventoryAPI,
  locationsAPI,
  categoriesAPI,
  batchAPI,
  dashboardAPI,
  scanEventsAPI,
  transactionTypesAPI,
} from '../services/api';

// Keep the parent isolated from the layout chrome (sidebar, auth context, etc.)
// — this test is only concerned with InventoryManagement's own hooks/effects.
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);

// react-barcode draws a canvas/SVG that jsdom cannot rasterise; the value it is
// handed is the only thing this page is responsible for.
jest.mock('react-barcode', () => (props) => <div data-testid="barcode">{props.value}</div>);

jest.mock('../services/api', () => ({
  inventoryAPI: {
    getAll: jest.fn(),
    getHistory: jest.fn(),
    getBatches: jest.fn(),
    getWarehouseBatches: jest.fn(),
    updateBatchZone: jest.fn(),
  },
  locationsAPI: { getAll: jest.fn() },
  categoriesAPI: { getAll: jest.fn() },
  batchAPI: { get: jest.fn() },
  dashboardAPI: { getStockMovementTrends: jest.fn() },
  scanEventsAPI: { getAll: jest.fn() },
  transactionTypesAPI: { getAll: jest.fn() },
}));

// CRA's Jest config uses resetMocks: true, which clears any implementation set
// at module-definition time before each test — so configure the mocks here.
beforeEach(() => {
  inventoryAPI.getAll.mockResolvedValue({ data: [], pagination: { total: 0, last_page: 1 } });
  inventoryAPI.getWarehouseBatches.mockResolvedValue({ data: [] });
  batchAPI.get.mockResolvedValue({ data: { locations: { data: [] }, categories: { data: [] } } });
  locationsAPI.getAll.mockResolvedValue({ data: [] });
  categoriesAPI.getAll.mockResolvedValue({ data: [] });
  dashboardAPI.getStockMovementTrends.mockResolvedValue({ data: null });
  scanEventsAPI.getAll.mockResolvedValue({ data: [] });
  transactionTypesAPI.getAll.mockResolvedValue({ data: [] });
});

/**
 * Regression guard for the TDZ crash fixed in 9c66f2e: a useCallback was
 * declared AFTER the `inventory-scan-applied` effect that referenced it in
 * both its handler body and its dependency array. const bindings stay in the
 * temporal dead zone until their declaration line runs, so every render threw
 * "Cannot access ... before initialization" and the whole page died on mount.
 *
 * These originally drove that through the Warehouse Map tab and
 * fetchWarehouseBatches. That tab has since moved off this page to
 * BarcodeScan, so the assertions are retargeted at the effect as it stands
 * now — it still closes over fetchInventory and fetchPoGroups, both declared
 * with useCallback, which is the same shape that broke.
 */
test('mounts without throwing and loads the inventory list', async () => {
  render(
    <MemoryRouter initialEntries={['/admin/inventory']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  // If the TDZ bug were present, render() above would already have thrown
  // synchronously. Confirm the page mounted and its own effect ran.
  await waitFor(() => expect(inventoryAPI.getAll).toHaveBeenCalled());
});

test('handles an inventory-scan-applied event without crashing', async () => {
  render(
    <MemoryRouter initialEntries={['/admin/inventory']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getAll).toHaveBeenCalled());
  const callsBefore = inventoryAPI.getAll.mock.calls.length;

  await act(async () => {
    window.dispatchEvent(new Event('inventory-scan-applied'));
  });

  // A re-triggered fetch confirms the handler ran to completion rather than
  // throwing partway through on an uninitialised binding.
  await waitFor(() =>
    expect(inventoryAPI.getAll.mock.calls.length).toBeGreaterThan(callsBefore)
  );
});

test('switching tabs does not crash', async () => {
  render(
    <MemoryRouter initialEntries={['/admin/inventory']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getAll).toHaveBeenCalled());

  fireEvent.click(screen.getByRole('button', { name: /activity/i }));

  await waitFor(() => expect(scanEventsAPI.getAll).toHaveBeenCalled());
});

/* ── Serial Numbers tab: material tags are listed and reprintable ───────────
   The reported bug: a material received without a supplier barcode showed
   "No serial numbers recorded", with no scannable/printable tag anywhere. */

const materialInventoryRow = {
  inventory_id: 77,
  quantity_on_hand: 40,
  available_quantity: 40,
  reorder_level: 5,
  product: {
    product_id: 237,
    product_name: 'PE Foam 1/2',
    product_type: 'installation_material',
    pieces_per_package: 10,
  },
};

const materialHistory = {
  success: true,
  data: {
    inventory: { inventory_id: 77, location_name: 'Main Warehouse', quantity_on_hand: 40 },
    product: { product_id: 237, product_name: 'PE Foam 1/2', product_type: 'installation_material', is_serialized: false, pieces_per_package: 10 },
    receiving_history: [],
    movement_history: [],
    serial_records: [
      {
        serial_id: 1, serial_number: 'INT-PO-20260828-ZIW7-237-01', supplier_barcode: '1234',
        serial_status: 'In Stock', is_material_tag: true, received_at: '2026-08-28 09:00:00',
        row_quantity_received: null, row_stock_in: null, row_stock_out: null, row_transfer: null,
      },
      {
        serial_id: 2, serial_number: 'INT-PO-20260828-ZIW7-237-02', supplier_barcode: null,
        serial_status: 'In Stock', is_material_tag: true, received_at: '2026-08-28 09:00:00',
        row_quantity_received: null, row_stock_in: null, row_stock_out: null, row_transfer: null,
      },
    ],
    summary: { total_receive_events: 1, serial_count: 2, stock_in: 40, stock_out: 0, transferred: 0, current_on_hand: 40, current_available: 40 },
  },
};

const openSerialsTab = async () => {
  inventoryAPI.getAll.mockResolvedValue({ data: [materialInventoryRow], pagination: { total: 1, last_page: 1 } });
  inventoryAPI.getHistory.mockResolvedValue(materialHistory);

  render(
    <MemoryRouter initialEntries={['/admin/inventory?focus=inv:77']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getHistory).toHaveBeenCalledWith(77));
  fireEvent.click(await screen.findByRole('button', { name: /serial numbers/i }));
};

test('lists the generated material tags instead of the empty-state message', async () => {
  await openSerialsTab();

  expect(await screen.findByText('INT-PO-20260828-ZIW7-237-01')).toBeInTheDocument();
  expect(screen.getByText('INT-PO-20260828-ZIW7-237-02')).toBeInTheDocument();
  expect(screen.queryByText(/No serial numbers recorded/i)).not.toBeInTheDocument();
  // The supplier code rides along as reference text only.
  expect(screen.getByText(/Supplier: 1234/i)).toBeInTheDocument();
});

test('reveals a scannable barcode per tag and prints only the sheet', async () => {
  await openSerialsTab();

  fireEvent.click(await screen.findByRole('button', { name: /view \/ print tags \(2\)/i }));

  const barcodes = screen.getAllByTestId('barcode').map((el) => el.textContent);
  expect(barcodes).toEqual(['INT-PO-20260828-ZIW7-237-01', 'INT-PO-20260828-ZIW7-237-02']);
  // Never the supplier code — it repeats across every box.
  expect(barcodes).not.toContain('1234');

  const printSpy = jest.spyOn(window, 'print').mockImplementation(() => {
    // The print-only stylesheet keys off this class; it must be on the body
    // while the dialog is open, or the whole dashboard goes to paper.
    expect(document.body.classList.contains('invd-printing-tags')).toBe(true);
  });

  fireEvent.click(screen.getByRole('button', { name: /print tags/i }));

  expect(printSpy).toHaveBeenCalled();
  expect(document.body.classList.contains('invd-printing-tags')).toBe(false);
  printSpy.mockRestore();
});

test("offers no tag barcode for an appliance whose serial is the manufacturer own", async () => {
  inventoryAPI.getAll.mockResolvedValue({
    data: [{ ...materialInventoryRow, inventory_id: 78, product: { product_id: 9, product_name: 'Split 1.5HP', product_type: 'appliance' } }],
    pagination: { total: 1, last_page: 1 },
  });
  inventoryAPI.getHistory.mockResolvedValue({
    success: true,
    data: {
      ...materialHistory.data,
      product: { product_id: 9, product_name: 'Split 1.5HP', product_type: 'appliance', is_serialized: true, pieces_per_package: 1 },
      serial_records: [{
        serial_id: 5, serial_number: 'SN-REAL-0001', serial_status: 'In Stock',
        received_at: '2026-08-28 09:00:00',
        row_quantity_received: 1, row_stock_in: 1, row_stock_out: 0, row_transfer: 0,
      }],
      summary: { ...materialHistory.data.summary, serial_count: 1 },
    },
  });

  render(
    <MemoryRouter initialEntries={['/admin/inventory?focus=inv:78']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getHistory).toHaveBeenCalledWith(78));
  fireEvent.click(await screen.findByRole('button', { name: /serial numbers/i }));

  expect(await screen.findByText('SN-REAL-0001')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /print tags/i })).not.toBeInTheDocument();
  expect(screen.queryAllByTestId('barcode')).toHaveLength(0);
});
