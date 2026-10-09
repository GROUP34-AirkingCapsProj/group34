import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import AlertsNotices from './AlertsNotices';
import { dashboardAPI, alertsAPI } from '../services/api';

jest.mock('react-router-dom', () => ({ useNavigate: () => jest.fn() }));
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../context/AuthContext', () => ({ useAuth: () => ({ can: () => false }) }));
jest.mock('../services/api', () => ({
  dashboardAPI: { getLowStock: jest.fn(), getPendingApprovals: jest.fn() },
  alertsAPI: { markAllRead: jest.fn(), getSettings: jest.fn() },
  auditAPI: {}, purchaseOrdersAPI: {},
}));
jest.mock('../utils/toast', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

beforeEach(() => {
  dashboardAPI.getLowStock.mockResolvedValue({ data: [] });
  alertsAPI.markAllRead.mockResolvedValue({});
  alertsAPI.getSettings.mockResolvedValue({ data: { warehouse_notifications: [] } });
});

test('shows warehouse work and refreshes it when notified, then marks it read', async () => {
  dashboardAPI.getPendingApprovals.mockResolvedValue({ data: {} });
  const notification = { id: 'transfer_receive:4', label: 'TR-4 incoming — ready to receive',
    description: 'Main → Branch', link: '/admin/transfers?tab=receive', unread: true };
  alertsAPI.getSettings.mockResolvedValue({ data: { warehouse_notifications: [notification] } });
  render(<AlertsNotices />);
  expect(await screen.findByText(notification.label)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Mark All Read' })).toBeEnabled();
  alertsAPI.getSettings.mockResolvedValue({ data: { warehouse_notifications: [{ ...notification, unread: false }] } });
  fireEvent.click(screen.getByRole('button', { name: 'Mark All Read' }));
  await waitFor(() => expect(screen.queryByText('New')).not.toBeInTheDocument());
  alertsAPI.getSettings.mockResolvedValue({ data: { warehouse_notifications: [] } });
  fireEvent(window, new Event('warehouse-notifications-updated'));
  await waitFor(() => expect(screen.queryByText(notification.label)).not.toBeInTheDocument());
});

test('keeps decisions visible, applies status colors, and counts only pending approvals', async () => {
  dashboardAPI.getPendingApprovals.mockResolvedValue({ data: {
    pending_pos: [{ id: 1, po_number: 'PO-approved', approval_status: 'approved' }],
    pending_prs: [{ id: 2, pr_number: 'PR-rejected', approval_status: 'rejected' }],
    pending_products: [{ id: 3, generated_name: 'Item-pending', approval_status: 'pending' }],
  } });
  render(<AlertsNotices />);
  expect((await screen.findByText('PO-approved')).closest('.an-approval-item')).toHaveClass('an-approval-item--approved');
  expect(screen.getByText('PR-rejected').closest('.an-approval-item')).toHaveClass('an-approval-item--rejected');
  expect(screen.getByText('Item-pending').closest('.an-approval-item')).toHaveClass('an-approval-item--pending');
  expect(screen.getByText('1 item')).toBeInTheDocument();
  expect(dashboardAPI.getPendingApprovals).toHaveBeenCalledWith(true);
  fireEvent.click(screen.getByRole('button', { name: 'Mark All Read' }));
  await screen.findByRole('button', { name: 'Mark All Read' });
  expect(screen.getByText('PO-approved')).toBeInTheDocument();
  expect(screen.getByText('PR-rejected')).toBeInTheDocument();
});

test('paginates ten records across approval categories', async () => {
  dashboardAPI.getPendingApprovals.mockResolvedValue({ data: {
    pending_pos: Array.from({ length: 9 }, (_, i) => ({ id: i, po_number: `Order-${i}`, approval_status: 'approved' })),
    pending_prs: [{ id: 20, pr_number: 'Request-first', approval_status: 'rejected' }, { id: 21, pr_number: 'Request-next', approval_status: 'pending' }],
    pending_products: [{ id: 22, generated_name: 'Registration-next', approval_status: 'approved' }],
  } });
  render(<AlertsNotices />);
  await screen.findByText('Order-0');
  expect(screen.getByText('Request-first')).toBeInTheDocument();
  expect(screen.queryByText('Request-next')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /^Next →$/ }));
  expect(screen.queryByText('Order-0')).not.toBeInTheDocument();
  expect(screen.getByText('Request-next')).toBeInTheDocument();
  expect(screen.getByText('Registration-next')).toBeInTheDocument();
});
