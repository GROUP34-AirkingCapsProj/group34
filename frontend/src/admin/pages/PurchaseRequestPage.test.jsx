import { render, screen } from '@testing-library/react';
import PurchaseRequestPage from './PurchaseRequestPage';
import { purchaseRequestsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';

jest.mock('react-router-dom', () => ({
  useNavigate: () => jest.fn(),
  useLocation: () => ({ pathname: '/purchase-requests', search: '', hash: '', state: null }),
}));
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../context/AuthContext', () => ({
  useAuth: jest.fn(),
}));
jest.mock('../services/api', () => ({
  itemsAPI:             { getAll: jest.fn() },
  locationsAPI:         { getAll: jest.fn() },
  suppliersAPI:         { getAll: jest.fn() },
  purchaseRequestsAPI:  { getAll: jest.fn(), getPending: jest.fn(), getLowStock: jest.fn(), history: jest.fn() },
  restockAPI:           { getSuggestions: jest.fn(), getRecommendations: jest.fn(), recalculate: jest.fn() },
}));
jest.mock('../utils/toast', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const pendingPR = {
  pr_id: 7,
  pr_number: 'PR-0007',
  status_id: 13,
  estimated_total: 1500,
  needed_by_date: '2026-12-01',
  request_date: '2026-10-13',
  requester: { first_name: 'Ana', last_name: 'Cruz' },
  supplier: { supplier_name: 'CoolParts Inc.' },
  details: [{ pr_detail_id: 1, product_name: 'Split Type 1.5HP', quantity: 2, estimated_price: 750, subtotal: 1500 }],
};

beforeEach(() => {
  useAuth.mockReturnValue({ user: { role: { role_name: 'admin' } }, can: () => true });
  const { itemsAPI, locationsAPI, suppliersAPI, restockAPI } = require('../services/api');
  itemsAPI.getAll.mockResolvedValue({ data: [] });
  locationsAPI.getAll.mockResolvedValue({ data: [] });
  suppliersAPI.getAll.mockResolvedValue({ data: [] });
  restockAPI.getSuggestions.mockResolvedValue({ data: [] });
  restockAPI.getRecommendations.mockResolvedValue({ data: {} });
  purchaseRequestsAPI.getAll.mockResolvedValue({ data: [] });
  purchaseRequestsAPI.getPending.mockResolvedValue({ data: [] });
});

test('shows pending approvals when branch manager permissions finish loading', async () => {
  const user = { role: { role_name: 'branch_manager' } };
  useAuth.mockReturnValue({ user, can: () => false });
  purchaseRequestsAPI.getPending.mockResolvedValue({ data: [pendingPR] });
  const { rerender } = render(<PurchaseRequestPage />);
  useAuth.mockReturnValue({ user, can: (feature, action) => feature === 'purchase_request_approval' && action === 'approve' });
  rerender(<PurchaseRequestPage />);
  expect(await screen.findByText('PR-0007')).toBeInTheDocument();
});

test('renders a pending purchase request card on the approvals tab', async () => {
  purchaseRequestsAPI.getPending.mockResolvedValue({ data: [pendingPR] });
  render(<PurchaseRequestPage />);
  expect(await screen.findByText('PR-0007')).toBeInTheDocument();
  expect(screen.getByText('Ana Cruz')).toBeInTheDocument();
  expect(screen.getByText('CoolParts Inc.')).toBeInTheDocument();
});
