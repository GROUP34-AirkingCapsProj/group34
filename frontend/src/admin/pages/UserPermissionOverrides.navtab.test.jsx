import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import UserPermissionOverrides from './UserPermissionOverrides';

// ── Mock sweetalert2 (toast uses it) ─────────────────────────────────────────
jest.mock('sweetalert2', () => ({
  mixin: () => ({ fire: jest.fn() }),
  stopTimer: jest.fn(),
  resumeTimer: jest.fn(),
}));

// ── Mock api.js — use the REAL export names ───────────────────────────────────
jest.mock('../services/api', () => ({
  navBundlesAPI: {
    list: () => Promise.resolve({
      data: [
        {
          bundle_id: 1,
          name: 'Item Master',
          route_path: '/admin/items',
          sidebar_section: 'PROCUREMENT & PLANNING',
          sidebar_icon: 'items',
          description: 'Manage items',
          sort_order: 1,
          permissions: [
            { permission_id: 10, feature: 'item_master_management', action: 'read', is_required: true },
          ],
        },
      ],
    }),
  },
  permissionsAPI: {
    catalog: () => Promise.resolve({ data: [] }),
    getUserPermissions: () => Promise.resolve({
      data: {
        effective: { 'item_master_management.read': true },
        overrides: [],
      },
    }),
    updateUserPermissions: () => Promise.resolve({ data: null }),
  },
  batchAPI: {
    get: () => Promise.resolve({
      data: {
        users: {
          data: [
            { user_id: 42, first_name: 'Alice', last_name: 'Smith', role: { role_name: 'Branch Manager' } },
          ],
        },
      },
    }),
  },
  authAPI: {},
  dashboardAPI: {},
  alertsAPI: {},
}));

// ── Mock components that have heavy dependencies ──────────────────────────────
jest.mock('../components/AdminLayout', () => ({ children }) => <div data-testid="admin-layout">{children}</div>);
jest.mock('../components/PermissionGuide', () => () => null);

// ─────────────────────────────────────────────────────────────────────────────

test('Navigation Access tab renders bundle cards', async () => {
  render(<UserPermissionOverrides initialUserId={42} />);

  // The "Navigation Access" tab control should be present
  const navTab = await screen.findByRole('tab', { name: /navigation access/i });
  expect(navTab).toBeInTheDocument();

  // Click the Navigation Access tab
  fireEvent.click(navTab);

  // The bundle card "Item Master" should appear
  await waitFor(() => expect(screen.getByText('Item Master')).toBeInTheDocument());
});

test('Advanced tab shows permission grid header', async () => {
  render(<UserPermissionOverrides initialUserId={42} />);

  // Find the Advanced tab and click it
  const advancedTab = await screen.findByRole('tab', { name: /advanced/i });
  fireEvent.click(advancedTab);

  // The existing grid should be present (either the table or the "select user" message)
  // With initialUserId=42, user is pre-selected so the grid area renders
  await waitFor(() => {
    // The grid tabs (feature group tabs inside Advanced) or the table itself
    expect(document.querySelector('.perm-admin')).toBeInTheDocument();
  });
});
