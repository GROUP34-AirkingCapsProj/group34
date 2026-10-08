import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import SystemReports from './SystemReports';
import { reportsAPI, locationsAPI, categoriesAPI } from '../services/api';

/**
 * Asserts the rewritten page renders entirely from the API — never from the
 * hardcoded `reportCards` / `recentReports` arrays the old mock page shipped.
 * "Branch Performance" and "Inventory_Summary_2025-01-19.pdf" are two of the
 * old fakes; their absence here is the regression guard against a rewrite
 * that quietly falls back to inventing data again.
 */

jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);

jest.mock('../services/api', () => ({
  reportsAPI: {
    getCatalog: jest.fn(),
    getRuns: jest.fn(),
    generate: jest.fn(),
    getFilterUsers: jest.fn(),
    fileUrl: jest.fn(() => 'http://example.test/file'),
  },
  locationsAPI: { getAll: jest.fn() },
  categoriesAPI: { getAll: jest.fn() },
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() },
}));

const inventoryReport = {
  key: 'inventory-summary',
  title: 'Inventory Summary Report',
  description: 'Complete inventory status across all locations',
  formats: ['pdf', 'csv'],
  parameters: [],
};

const csvOnlyReport = {
  key: 'low-stock',
  title: 'Low Stock Report',
  description: 'Items below reorder level',
  formats: ['csv'],
  parameters: [],
};

const activeRun = {
  report_run_id: 1,
  report_key: 'inventory-summary',
  title_snapshot: 'Inventory Summary Report',
  file_name: 'inventory-summary-20260101.pdf',
  format: 'pdf',
  created_at: '2026-01-01T09:00:00Z',
  // Laravel snake-cases relation keys, and relations are merged OVER
  // attributes — so `generated_by` on the wire is the eager-loaded user
  // object, not the id column, and there is no `generatedBy` key at all.
  generated_by: { user_id: 4, first_name: 'Dana', last_name: 'Reyes' },
  row_count: 12,
  file_size_bytes: 2048,
  is_expired: false,
  parameters: {},
};

const expiredRun = {
  ...activeRun,
  report_run_id: 2,
  is_expired: true,
  file_size_bytes: null,
};

const setupDefaults = () => {
  reportsAPI.getCatalog.mockResolvedValue({ data: [inventoryReport, csvOnlyReport] });
  reportsAPI.getRuns.mockResolvedValue({ data: [activeRun] });
  reportsAPI.generate.mockResolvedValue({ data: { ...activeRun, report_run_id: 3 } });
  locationsAPI.getAll.mockResolvedValue({ data: [] });
  categoriesAPI.getAll.mockResolvedValue({ data: [] });
  reportsAPI.getFilterUsers.mockResolvedValue({ data: [] });
};

beforeEach(() => {
  jest.clearAllMocks();
  setupDefaults();
});

test('cards render from the catalog, not a hardcoded list', async () => {
  render(<SystemReports />);

  expect(await screen.findByText('Inventory Summary Report')).toBeInTheDocument();
  expect(screen.queryByText('Branch Performance')).not.toBeInTheDocument();
});

test('only declared formats are advertised', async () => {
  render(<SystemReports />);

  await screen.findByText('Low Stock Report');
  const card = screen.getByText('Low Stock Report').closest('.sr-card');
  expect(within(card).getByText('CSV')).toBeInTheDocument();
  expect(within(card).queryByText(/PDF \/ Excel/)).not.toBeInTheDocument();
  expect(within(card).queryByText('PDF')).not.toBeInTheDocument();
});

test('a recent run names the person who generated it', async () => {
  render(<SystemReports />);

  expect(await screen.findByText(/by Dana Reyes/)).toBeInTheDocument();
  expect(screen.queryByText(/by Unknown user/)).not.toBeInTheDocument();
});

/**
 * The user filter has to come from a route every report-viewing role can
 * reach. GET /users is gated on user_account_management.read, seeded to admin
 * alone, so sourcing it there left an auditor or inventory analyst with a
 * swallowed 403 and a dropdown containing only "All users".
 */
test('the user filter is loaded from the reports endpoint, not the admin-only user list', async () => {
  render(<SystemReports />);

  await waitFor(() => expect(reportsAPI.getFilterUsers).toHaveBeenCalled());
});

test('an empty state replaces the invented filenames', async () => {
  reportsAPI.getCatalog.mockResolvedValue({ data: [] });
  reportsAPI.getRuns.mockResolvedValue({ data: [] });

  render(<SystemReports />);

  expect(await screen.findByText(/No reports are available/i)).toBeInTheDocument();
  expect(screen.queryByText('Inventory_Summary_2025-01-19.pdf')).not.toBeInTheDocument();
});

test('an expired run offers Re-run, not Download', async () => {
  reportsAPI.getRuns.mockResolvedValue({ data: [expiredRun] });

  render(<SystemReports />);

  expect(await screen.findByRole('button', { name: /re-run/i })).toBeInTheDocument();
  expect(screen.queryByText('Download')).not.toBeInTheDocument();
});

test('generating refreshes the runs list', async () => {
  render(<SystemReports />);

  const generateButtons = await screen.findAllByRole('button', { name: /generate report/i });
  fireEvent.click(generateButtons[0]);

  const dialog = await screen.findByRole('dialog');
  const submit = within(dialog).getByRole('button', { name: /^generate$/i });
  fireEvent.click(submit);

  await waitFor(() => expect(reportsAPI.generate).toHaveBeenCalledTimes(1));
  await waitFor(() => expect(reportsAPI.getRuns).toHaveBeenCalledTimes(2));
});
