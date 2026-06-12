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
            { permission_id: 11, feature: 'item_master_management', action: 'create', is_required: false },
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

test('Advanced tab shows the feature-group tablist (perm-tabs)', async () => {
  render(<UserPermissionOverrides initialUserId={42} />);

  // Find the Advanced tab and click it
  const advancedTab = await screen.findByRole('tab', { name: /advanced/i });
  fireEvent.click(advancedTab);

  // .perm-tabs is the feature-group tablist rendered ONLY inside the Advanced panel.
  // It must be present after switching to Advanced; it must NOT exist on the Nav tab.
  await waitFor(() => {
    expect(document.querySelector('.perm-tabs')).toBeInTheDocument();
  });
});

test('bundle toggle shows ON when required perm is granted even if action perm is not', async () => {
  // effective map: required 'read' is granted; action 'create' is NOT granted.
  // bundleState must return 'on' because it only looks at is_required perms.
  render(<UserPermissionOverrides initialUserId={42} />);

  const navTab = await screen.findByRole('tab', { name: /navigation access/i });
  fireEvent.click(navTab);

  // Wait for the Item Master bundle card toggle to appear
  await waitFor(() => expect(screen.getByText('Item Master')).toBeInTheDocument());

  // The toggle button should have aria-pressed="true" (state === 'on').
  // The button's accessible name is its text content ("ON"/"OFF"); use title to find it.
  const toggle = screen.getByTitle(/disable item master/i);
  expect(toggle).toHaveAttribute('aria-pressed', 'true');
});
