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

// Regression test for the TDZ crash fixed in 9c66f2e: fetchWarehouseBatches
// was declared (via useCallback) AFTER the `inventory-scan-applied` effect
// that referenced it in both its handler body and dependency array. Because
// const bindings stay in the temporal dead zone until their declaration line
// runs, every render threw "Cannot access 'fetchWarehouseBatches' before
// initialization" — crashing the whole page on mount.
test('mounts without throwing and loads the Warehouse Map tab', async () => {
  render(
    <MemoryRouter initialEntries={['/admin/inventory?tab=warehouse-map']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  // If the TDZ bug were present, render() above would already have thrown
  // synchronously. Confirm the page actually mounted and the warehouse-map
  // tab's own effect ran (it also calls fetchWarehouseBatches).
  await waitFor(() => expect(inventoryAPI.getWarehouseBatches).toHaveBeenCalled());
  await waitFor(() =>
    expect(screen.getByText(/no batches with remaining stock/i)).toBeInTheDocument()
  );
});

// Exercises the exact code path that crashed: the `inventory-scan-applied`
// window event handler, which calls fetchWarehouseBatches() when the
// Warehouse Map tab is active. Dispatching this event is what triggered the
// ReferenceError before the fix, since the handler closed over a binding
// that hadn't been initialized yet.
test('handles an inventory-scan-applied event while Warehouse Map is active without crashing', async () => {
  render(
    <MemoryRouter initialEntries={['/admin/inventory?tab=warehouse-map']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getWarehouseBatches).toHaveBeenCalledTimes(1));

  await act(async () => {
    window.dispatchEvent(new Event('inventory-scan-applied'));
  });

  // A second, re-triggered fetch confirms the handler ran fully (i.e. it
  // reached and called fetchWarehouseBatches) rather than throwing partway
  // through before ever reaching it.
  await waitFor(() => expect(inventoryAPI.getWarehouseBatches).toHaveBeenCalledTimes(2));
  await waitFor(() =>
    expect(screen.getByText(/no batches with remaining stock/i)).toBeInTheDocument()
  );
});

test('switching to the Warehouse Map tab via the tab button does not crash', async () => {
  render(
    <MemoryRouter initialEntries={['/admin/inventory']}>
      <InventoryManagement />
    </MemoryRouter>
  );

  await waitFor(() => expect(inventoryAPI.getAll).toHaveBeenCalled());

  fireEvent.click(screen.getByRole('button', { name: /warehouse map/i }));

  await waitFor(() => expect(inventoryAPI.getWarehouseBatches).toHaveBeenCalled());
  await waitFor(() =>
    expect(screen.getByText(/no batches with remaining stock/i)).toBeInTheDocument()
  );
});
