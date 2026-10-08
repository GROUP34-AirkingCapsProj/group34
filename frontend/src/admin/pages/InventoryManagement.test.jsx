import { render, screen, waitFor, fireEvent, act, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import InventoryManagement from './InventoryManagement';
import { toast } from '../utils/toast';

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

// The toast helper drives sweetalert2, which needs a real document to open in.
// Only the message matters here.
// Where a sticker goes is useLabelPrinter's call and is covered by its own
// tests. What matters here is which tags this page hands over.
jest.mock('../hooks/useLabelPrinter', () => ({
  useLabelPrinter: () => global.__labelPrinter,
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() },
}));

// react-barcode draws a canvas/SVG that jsdom cannot rasterise; the value it is
// handed is the only thing this page is responsible for. `displayValue` is
// honoured so the inline table barcodes stay text-free here exactly as they are
// in a browser — otherwise every serial would appear twice in the DOM.
jest.mock('react-barcode', () => (props) => (
  <div data-testid="barcode" data-value={props.value}>
    {props.displayValue === false ? null : props.value}
  </div>
));

jest.mock('../services/api', () => ({
  inventoryAPI: {
    getAll: jest.fn(),
    getHistory: jest.fn(),
    getBatches: jest.fn(),
    getWarehouseBatches: jest.fn(),
    updateBatchZone: jest.fn(),
    getPoGroups: jest.fn(),
    getPoGroupDetail: jest.fn(),
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
  global.__labelPrinter = {
    printerReady: true,
    sending: false,
    sendToPrinter: jest.fn(),
    savePdf: jest.fn(),
  };
  inventoryAPI.getAll.mockResolvedValue({ data: [], pagination: { total: 0, last_page: 1 } });
  inventoryAPI.getWarehouseBatches.mockResolvedValue({ data: [] });
  inventoryAPI.getPoGroups.mockResolvedValue({ data: [] });
  inventoryAPI.getPoGroupDetail.mockResolvedValue({ data: null });
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
    // Row 1 is a generated tag (no supplier code of its own) — scannable and
    // printable. Row 2 is a scanned material: its item_serial row holds the
    // supplier's own code in both serial_number and supplier_barcode, per the
    // decision that a scanned material is scannable (that code is the
    // identifier) but not printable (the supplier already printed it).
    serial_records: [
      {
        serial_id: 1, serial_number: 'INT-PO-20260828-ZIW7-237-01', supplier_barcode: null,
        serial_status: 'In Stock', is_material_tag: true, received_at: '2026-08-28 09:00:00',
        row_quantity_received: null, row_stock_in: null, row_stock_out: null, row_transfer: null,
        batch_id: 35, batch_quantity_received: 40, batch_quantity_remaining: 32,
        count_unit: 'package', pieces_per_package: 10, scanned_by_name: 'Marichu Contado',
      },
      {
        serial_id: 2, serial_number: 'SUP-9911', supplier_barcode: 'SUP-9911',
        serial_status: 'In Stock', is_material_tag: true, received_at: '2026-08-28 09:00:00',
        row_quantity_received: null, row_stock_in: null, row_stock_out: null, row_transfer: null,
        batch_id: 35, batch_quantity_received: 40, batch_quantity_remaining: 32,
        count_unit: 'package', pieces_per_package: 10, scanned_by_name: 'Marichu Contado',
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
  expect(screen.getByText('SUP-9911')).toBeInTheDocument();
  expect(screen.queryByText(/No serial numbers recorded/i)).not.toBeInTheDocument();
});

test('renders each tag row own scannable barcode inline, beside the serial', async () => {
  await openSerialsTab();

  await screen.findByText('INT-PO-20260828-ZIW7-237-01');

  // No sheet opened: the barcodes are in the table itself, one per tag row —
  // scannable whether the value is a generated tag or the supplier's own code.
  const values = screen.getAllByTestId('barcode').map((el) => el.getAttribute('data-value'));
  expect(values).toEqual(expect.arrayContaining(['INT-PO-20260828-ZIW7-237-01', 'SUP-9911']));
  expect(values).toHaveLength(2);
});

/*
 * A scanned material (supplier_barcode populated) is still scannable — its
 * supplier code is the identifier — but it is not printable: the supplier
 * already printed that code, and reprinting it in our own format would put
 * two labels of differing provenance on one box. A generated tag has no
 * supplier code and stays both scannable and printable. Gated on
 * `supplier_barcode === null`, not an `INT-` prefix match.
 */
test('a scanned material is scannable but not printable; a generated tag is both', async () => {
  await openSerialsTab();

  const generatedRow = (await screen.findByText('INT-PO-20260828-ZIW7-237-01')).closest('tr');
  const scannedRow   = (await screen.findByText('SUP-9911')).closest('tr');

  expect(generatedRow.querySelectorAll('[data-testid="barcode"]')).toHaveLength(1);
  expect(scannedRow.querySelectorAll('[data-testid="barcode"]')).toHaveLength(1);

  expect(within(generatedRow).getByRole('button', { name: /print tag$/i })).toBeInTheDocument();
  expect(within(scannedRow).queryByRole('button', { name: /print tag$/i })).not.toBeInTheDocument();
});

/* Stickers go to a PDF of their own, not to this page. The old approach
   printed the dashboard with everything but the labels hidden, which is how a
   sticker ended up in the middle of a document-sized sheet. */
const printedValues = () =>
  global.__labelPrinter.sendToPrinter.mock.calls.at(-1)[0].map((tag) => tag.serial_number);

test('prints one tag from its own row without dragging the rest onto paper', async () => {
  await openSerialsTab();

  await screen.findByText('INT-PO-20260828-ZIW7-237-01');
  // Only the generated tag is printable — the scanned material has no print button.
  const printButtons = screen.getAllByRole('button', { name: /print tag$/i });
  expect(printButtons).toHaveLength(1);

  fireEvent.click(printButtons[0]);

  expect(global.__labelPrinter.sendToPrinter).toHaveBeenCalledTimes(1);
  expect(printedValues()).toEqual(['INT-PO-20260828-ZIW7-237-01']);
});

test('falls back to a PDF when no label printer is set up', async () => {
  global.__labelPrinter.printerReady = false;
  await openSerialsTab();
  await screen.findByText('INT-PO-20260828-ZIW7-237-01');

  fireEvent.click(screen.getAllByRole('button', { name: /print tag$/i })[0]);

  expect(global.__labelPrinter.sendToPrinter).not.toHaveBeenCalled();
  expect(global.__labelPrinter.savePdf).toHaveBeenCalledTimes(1);
  // The sticker still names the product it belongs to.
  expect(global.__labelPrinter.savePdf.mock.calls[0][1]).toBeTruthy();
});

test('still offers the bulk sheet for printing every live tag at once', async () => {
  await openSerialsTab();

  // The scanned material is excluded from the printable count — only the
  // generated tag can go on the bulk sheet.
  fireEvent.click(await screen.findByRole('button', { name: /view \/ print tags \(1\)/i }));

  const sheetBarcodes = document.querySelectorAll('.invd-tag-sheet-wrap [data-testid="barcode"]');
  expect(Array.from(sheetBarcodes).map((el) => el.getAttribute('data-value')))
    .toEqual(['INT-PO-20260828-ZIW7-237-01']);

  // The sheet drives its own bulk printing now; this page only supplies the
  // tags it may print.
  const sheetBarcodeValues = Array.from(
    document.querySelectorAll('.invd-tag-sheet-wrap [data-testid="barcode"]'),
  ).map((el) => el.getAttribute('data-value'));

  expect(sheetBarcodeValues).toEqual(['INT-PO-20260828-ZIW7-237-01']);
});

/* ── Stock-In History tab: a counted line must not read as empty ──────────
   The reported bug: with one scanned box and two counted ones, only the
   scanned row showed anything, because the column rendered scanned_barcode
   alone and a counted line has none. */

const openStockInTab = async (receivingHistory) => {
  inventoryAPI.getAll.mockResolvedValue({ data: [materialInventoryRow], pagination: { total: 1, last_page: 1 } });
  inventoryAPI.getHistory.mockResolvedValue({
    ...materialHistory,
    data: { ...materialHistory.data, receiving_history: receivingHistory },
  });

  render(
    <MemoryRouter initialEntries={['/admin/inventory?focus=inv:77']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getHistory).toHaveBeenCalledWith(77));
  fireEvent.click(await screen.findByRole('button', { name: /stock-in history/i }));
};

test('a counted stock-in row shows the tags minted for it, not a blank cell', async () => {
  await openStockInTab([
    {
      receiving_detail_id: 1, receiving_number: 'RCV-1', received_at: '2026-08-28 09:00:00',
      scanned_barcode: null, quantity_amount: 20, prod_price: 5, condition: 'Good',
      generated_tags: ['INT-PO-20260828-ZIW7-237-01', 'INT-PO-20260828-ZIW7-237-02'],
      generated_tag_count: 2, tag_attribution: 'exact', tag_line_count: 1,
    },
    {
      receiving_detail_id: 2, receiving_number: 'RCV-1', received_at: '2026-08-28 09:05:00',
      scanned_barcode: 'SUP-9911', quantity_amount: 10, prod_price: 5, condition: 'Good',
      generated_tags: ['INT-PO-20260828-ZIW7-237-03'],
      generated_tag_count: 1, tag_attribution: 'exact', tag_line_count: 1,
    },
  ]);

  expect(await screen.findByText('INT-PO-20260828-ZIW7-237-01')).toBeInTheDocument();
  expect(screen.getByText('INT-PO-20260828-ZIW7-237-02')).toBeInTheDocument();
  expect(screen.getByText('INT-PO-20260828-ZIW7-237-03')).toBeInTheDocument();
  expect(screen.getByText('SUP-9911')).toBeInTheDocument();
  expect(screen.getByText(/No supplier barcode \(counted\)/i)).toBeInTheDocument();
  expect(screen.queryByText(/Shared across/i)).not.toBeInTheDocument();
});

test('a stock-in row whose tags cannot be pinned to one line says so', async () => {
  await openStockInTab([
    {
      receiving_detail_id: 1, receiving_number: 'RCV-1', received_at: '2026-08-28 09:00:00',
      scanned_barcode: null, quantity_amount: 20, prod_price: 5, condition: 'Good',
      generated_tags: ['INT-A-01', 'INT-A-02', 'INT-A-03'],
      generated_tag_count: 3, tag_attribution: 'shared', tag_line_count: 2,
    },
  ]);

  // Three tags collapse behind a disclosure so the row stays one line high.
  // Labelled "tags", not "generated tags": the field can carry a scanned
  // material's own supplier code as easily as one this system minted, and
  // that value echoed back as "generated" would be actively misleading.
  expect(await screen.findByText('3 tags')).toBeInTheDocument();
  expect(screen.getByText(/Shared across 2 lines of this receipt/i)).toBeInTheDocument();
});

/* ── Stock-In History tab: package shortfall sub-line ─────────────────────
   A box marked 50 that only held 47 used to post 50 and the shortfall
   vanished. pieces_expected (packages x pieces-per-package, frozen at
   receipt) versus quantity_amount (what actually posted) is what finally
   distinguishes a short-filled box from one that simply never arrived. */

test('shows a shortfall sub-line when pieces_expected and quantity_amount disagree', async () => {
  await openStockInTab([
    {
      receiving_detail_id: 1, receiving_number: 'RCV-1', received_at: '2026-08-28 09:00:00',
      scanned_barcode: null, quantity_amount: 297, prod_price: 5, condition: 'Good',
      packages_received: 6, pieces_expected: 300,
      generated_tags: [], generated_tag_count: 0, tag_attribution: 'exact', tag_line_count: 1,
    },
  ]);

  expect(await screen.findByText('297')).toBeInTheDocument();
  expect(screen.getByText(/300 expected/i)).toBeInTheDocument();
  expect(screen.getByText(/3 short/i)).toBeInTheDocument();
});

test('shows "over" rather than "short" when more posted than expected', async () => {
  await openStockInTab([
    {
      receiving_detail_id: 1, receiving_number: 'RCV-1', received_at: '2026-08-28 09:00:00',
      scanned_barcode: null, quantity_amount: 305, prod_price: 5, condition: 'Good',
      packages_received: 6, pieces_expected: 300,
      generated_tags: [], generated_tag_count: 0, tag_attribution: 'exact', tag_line_count: 1,
    },
  ]);

  expect(await screen.findByText('305')).toBeInTheDocument();
  expect(screen.getByText(/300 expected/i)).toBeInTheDocument();
  expect(screen.getByText(/5 over/i)).toBeInTheDocument();
});

test('renders no sub-line when pieces_expected matches quantity_amount', async () => {
  await openStockInTab([
    {
      receiving_detail_id: 1, receiving_number: 'RCV-1', received_at: '2026-08-28 09:00:00',
      scanned_barcode: null, quantity_amount: 300, prod_price: 5, condition: 'Good',
      packages_received: 6, pieces_expected: 300,
      generated_tags: [], generated_tag_count: 0, tag_attribution: 'exact', tag_line_count: 1,
    },
  ]);

  expect(await screen.findByText('300')).toBeInTheDocument();
  expect(screen.queryByText(/expected/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/short/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/\bover\b/i)).not.toBeInTheDocument();
});

test('renders no sub-line when pieces_expected is null (piece-counted / appliance line)', async () => {
  await openStockInTab([
    {
      receiving_detail_id: 1, receiving_number: 'RCV-1', received_at: '2026-08-28 09:00:00',
      scanned_barcode: null, quantity_amount: 473, prod_price: 5, condition: 'Good',
      packages_received: null, pieces_expected: null,
      generated_tags: [], generated_tag_count: 0, tag_attribution: 'exact', tag_line_count: 1,
    },
  ]);

  expect(await screen.findByText('473')).toBeInTheDocument();
  expect(screen.queryByText(/expected/i)).not.toBeInTheDocument();
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


/* ── Serial Numbers table: no column that is blank on every row ──────────
 *
 * A material tag carries no quantity of its own, so Received / Stock In /
 * Stock Out / Transfer used to render as a run of dashes on every row while
 * the batch the tag resolves to — and what one tag physically stands for —
 * were not shown at all. The table now picks its columns from the data.
 */
test('drops the per-unit quantity columns a material tag can never fill', async () => {
  await openSerialsTab();
  await screen.findByText('INT-PO-20260828-ZIW7-237-01');

  const headers = screen.getAllByRole('columnheader').map((th) => th.textContent);
  expect(headers).not.toContain('Stock In');
  expect(headers).not.toContain('Stock Out');
  expect(headers).not.toContain('Transfer');
  expect(headers).not.toContain('Stocked Out At');
  expect(headers).not.toContain('Transferred At');
});

test('shows the batch a material tag resolves to, and what the tag covers', async () => {
  await openSerialsTab();

  const row = (await screen.findByText('INT-PO-20260828-ZIW7-237-01')).closest('tr');

  expect(within(row).getByText('#35')).toBeInTheDocument();
  expect(within(row).getByText('32 / 40')).toBeInTheDocument();
  // One tag per package for a package-counted product. The piece count is
  // deliberately absent: the last package of a lot can be a partial one.
  expect(within(row).getByText('1 package')).toBeInTheDocument();
});

test('keeps the per-unit columns for an appliance, which does fill them', async () => {
  inventoryAPI.getAll.mockResolvedValue({
    data: [{
      inventory_id: 88, quantity_on_hand: 1, available_quantity: 1, reorder_level: 1,
      product: { product_id: 500, product_name: 'Split Type 1.5HP', product_type: 'appliance' },
    }],
    pagination: { total: 1, last_page: 1 },
  });
  inventoryAPI.getHistory.mockResolvedValue({
    success: true,
    data: {
      inventory: { inventory_id: 88, location_name: 'Main Warehouse', quantity_on_hand: 1 },
      product: { product_id: 500, product_name: 'Split Type 1.5HP', product_type: 'appliance', is_serialized: true },
      receiving_history: [], movement_history: [],
      serial_records: [{
        serial_id: 9, serial_number: 'SN-APPLIANCE-1', serial_status: 'In Stock',
        received_at: '2026-08-28 09:00:00',
        row_quantity_received: 1, row_stock_in: 1, row_stock_out: 0, row_transfer: 0,
      }],
      summary: { total_receive_events: 1, serial_count: 1, stock_in: 1, stock_out: 0, transferred: 0, current_on_hand: 1, current_available: 1 },
    },
  });

  render(
    <MemoryRouter initialEntries={['/admin/inventory?focus=inv:88']}>
      <InventoryManagement />
    </MemoryRouter>
  );
  await waitFor(() => expect(inventoryAPI.getHistory).toHaveBeenCalledWith(88));
  fireEvent.click(await screen.findByRole('button', { name: /serial numbers/i }));

  await screen.findByText('SN-APPLIANCE-1');
  const headers = screen.getAllByRole('columnheader').map((th) => th.textContent);
  expect(headers).toEqual(expect.arrayContaining(['Stock In', 'Stock Out', 'Transfer']));
  // Nothing minted a tag of ours for a manufacturer's serial, so the columns
  // that only exist to carry one stay off the table.
  expect(headers).not.toContain('Digital Barcode');
  expect(headers).not.toContain('Tag Covers');
});

/* ── Inventory Batch modal shares the same table ─────────────────────── */

const poGroup = {
  group_key: '76-1', po_id: 76, po_number: 'PO-20260901-5YOK',
  warehouse_id: 1, warehouse_name: 'Main Warehouse', warehouse_type: 'warehouse',
  supplier_name: 'Supplier A', order_date: '2026-09-01', first_received_at: '2026-09-01',
  location_label: 'Location 1', item_count: 1, total_received: 40, total_remaining: 32,
  batches: [],
};

const poGroupDetail = {
  data: {
    overview: {
      po_number: 'PO-20260901-5YOK', supplier_name: 'Supplier A', order_date: '2026-09-01',
      warehouse_name: 'Main Warehouse', location_label: 'Location 1',
      totals: { item_count: 1, total_received: 40, total_remaining: 32 },
      products: [{ product_id: 237, product_name: 'PE Foam 1/2', quantity_received: 40, quantity_remaining: 32 }],
    },
    receiving_history: [], stock_out_history: [], transfer_history: [],
    serials: [{
      serial_id: 1, serial_number: 'INT-PO-20260901-5YOK-237-01', supplier_barcode: null,
      product_id: 237, product_name: 'PE Foam 1/2', serial_type: 'received',
      serial_status: 'In Stock', scanned_by_name: 'Marichu Contado',
      scanned_at: '2026-09-01 18:02:26', received_at: '2026-09-01 18:02:26',
      receiving_number: 'GRN-20260901-000054',
      batch_id: 35, batch_quantity_received: 40, batch_quantity_remaining: 32,
      row_quantity_received: null, is_material_tag: true,
      count_unit: 'package', pieces_per_package: 10,
    }],
  },
};

const openPoGroupSerials = async () => {
  inventoryAPI.getPoGroups.mockResolvedValue({ data: [poGroup] });
  inventoryAPI.getPoGroupDetail.mockResolvedValue(poGroupDetail);

  render(
    <MemoryRouter initialEntries={['/admin/inventory?tab=inventory']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getPoGroups).toHaveBeenCalled());
  fireEvent.click(await screen.findByRole('button', { name: /^View/ }));
  await waitFor(() => expect(inventoryAPI.getPoGroupDetail).toHaveBeenCalledWith(76, 1));
  fireEvent.click(await screen.findByRole('button', { name: /^Serial Numbers$/ }));
};

test('the Inventory Batch serials tab renders the same detailed table', async () => {
  await openPoGroupSerials();

  const row = (await screen.findByText('INT-PO-20260901-5YOK-237-01')).closest('tr');

  // The four-column list it used to be carried none of this.
  expect(within(row).getByTestId('barcode')).toHaveAttribute('data-value', 'INT-PO-20260901-5YOK-237-01');
  expect(within(row).getByRole('button', { name: /print tag/i })).toBeInTheDocument();
  expect(within(row).getByText('PE Foam 1/2')).toBeInTheDocument();
  expect(within(row).getByText('#35')).toBeInTheDocument();
  expect(within(row).getByText('32 / 40')).toBeInTheDocument();
  expect(within(row).getByText('1 package')).toBeInTheDocument();
  expect(within(row).getByText('GRN-20260901-000054')).toBeInTheDocument();
});

test('printing a tag from the Inventory Batch modal sends that one tag', async () => {
  await openPoGroupSerials();

  const row = (await screen.findByText('INT-PO-20260901-5YOK-237-01')).closest('tr');
  fireEvent.click(within(row).getByRole('button', { name: /print tag/i }));

  expect(global.__labelPrinter.sendToPrinter).toHaveBeenCalledTimes(1);
  const [sent] = global.__labelPrinter.sendToPrinter.mock.calls[0];
  expect(sent.map((t) => t.serial_number)).toEqual(['INT-PO-20260901-5YOK-237-01']);
});
