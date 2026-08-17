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
