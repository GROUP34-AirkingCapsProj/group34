import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AuditEvidenceExplorer from './AuditEvidenceExplorer';

jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../components/Pagination', () => () => <div data-testid="pagination" />);
jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({
    user: { user_id: 1 },
    permissions: { 'audit_evidence.resolve_exception': true },
  }),
}));
jest.mock('../services/api', () => ({
  auditEvidenceAPI: {
    getSummary: jest.fn(),
    getBreakdown: jest.fn(),
    getMovements: jest.fn(),
    getReconciliation: jest.fn(),
    getExceptions: jest.fn(),
    getEvent: jest.fn(),
    getSerialLifecycle: jest.fn(),
    getBatchLifecycle: jest.fn(),
    resolveException: jest.fn(),
  },
}));

const { auditEvidenceAPI } = require('../services/api');

const SUMMARY = {
  quantity_in: 10, quantity_out: 4, net_quantity: 6,
  value_in: 1000, value_out: 400, net_value: 600,
  event_count: 3, row_count: 5, reversal_count: 0, unvalued_rows: 0,
  cost_basis: { actual: 1000, product_default: 0, derived_weighted_avg: 0, unavailable: 0 },
};

const renderPage = (path = '/audit-evidence') =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <AuditEvidenceExplorer />
    </MemoryRouter>
  );

describe('AuditEvidenceExplorer', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    auditEvidenceAPI.getSummary.mockResolvedValue({ data: SUMMARY });
    auditEvidenceAPI.getBreakdown.mockResolvedValue({
      data: [
        { key: 'receiving', label: 'Receiving', quantity_in: 10, quantity_out: 0, value_in: 1000, value_out: 0, row_count: 2, event_count: 2 },
      ],
    });
    auditEvidenceAPI.getMovements.mockResolvedValue({
      data: [], pagination: { total: 0, last_page: 1, current_page: 1, per_page: 50 },
    });
    auditEvidenceAPI.getReconciliation.mockResolvedValue({ data: null });
    auditEvidenceAPI.getExceptions.mockResolvedValue({ data: [] });
  });

  it('loads the summary and the breakdown for the default scope', async () => {
    renderPage();

    await waitFor(() => expect(screen.getByTestId('tile-quantity-in')).toHaveTextContent('10'));
    await waitFor(() => expect(screen.getByText('Receiving')).toBeInTheDocument());
  });

  it('switching to the finance lens refetches with that lens', async () => {
    renderPage();

    await waitFor(() => expect(auditEvidenceAPI.getSummary).toHaveBeenCalled());

    fireEvent.click(screen.getByRole('button', { name: /^finance$/i }));

    await waitFor(() =>
      expect(auditEvidenceAPI.getSummary).toHaveBeenLastCalledWith(expect.objectContaining({ lens: 'finance' }))
    );
  });

  it('shows the server refusal for an over-wide period rather than zeros', async () => {
    auditEvidenceAPI.getSummary.mockRejectedValueOnce(
      new Error('That period is wider than the 366 days this view can total at once.')
    );

    renderPage();

    await waitFor(() => expect(screen.getByTestId('explorer-error')).toHaveTextContent('366 days'));
  });

  it('drilling into a breakdown row narrows the same scope and switches to movements', async () => {
    renderPage();

    await waitFor(() => expect(screen.getByText('Receiving')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /view movements/i }));

    await waitFor(() =>
      expect(auditEvidenceAPI.getMovements).toHaveBeenCalledWith(
        expect.objectContaining({ movement_type: 'receiving' })
      )
    );
  });

  it('opens the exceptions tab on request', async () => {
    renderPage();

    await waitFor(() => expect(auditEvidenceAPI.getSummary).toHaveBeenCalled());

    fireEvent.click(screen.getByRole('button', { name: /exceptions/i }));

    await waitFor(() => expect(auditEvidenceAPI.getExceptions).toHaveBeenCalled());
  });
});
