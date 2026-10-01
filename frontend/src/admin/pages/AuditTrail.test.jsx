import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import AuditTrail from './AuditTrail';

jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../components/Pagination', () => () => <div data-testid="pagination" />);
jest.mock('../utils/toast', () => ({ toast: { error: jest.fn(), success: jest.fn() } }));
jest.mock('../services/api', () => ({
  auditAPI: {
    getTrail: jest.fn(),
    getFilterOptions: jest.fn(),
    getLoginSessions: jest.fn(),
  },
}));

const { auditAPI } = require('../services/api');

const ROW = {
  audit_id: 1,
  action: 'update',
  table_name: 'products',
  record_id: 42,
  new_values: { product_name: 'Split Type' },
  old_values: { product_name: 'Window Type' },
  created_at: '2026-09-30T10:00:00+08:00',
  user: { email: 'admin@airking.com' },
};

describe('AuditTrail activity filters', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    auditAPI.getTrail.mockResolvedValue({ data: { data: [ROW] } });
    auditAPI.getFilterOptions.mockResolvedValue({
      data: {
        actions: [{ value: 'update', label: 'Record Updated' }, { value: 'create', label: 'Record Created' }],
        tables: [{ value: 'products', label: 'Product' }],
        users: [{ value: 7, label: 'Mari Contado' }],
      },
    });
    auditAPI.getLoginSessions.mockResolvedValue({ data: [] });
  });

  it('loads the feed unfiltered and populates the dropdowns from the server', async () => {
    render(<AuditTrail />);

    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalled());
    expect(auditAPI.getTrail).toHaveBeenCalledWith({ per_page: 200 });

    await waitFor(() => expect(screen.getByRole('option', { name: 'Record Updated' })).toBeInTheDocument());
    expect(screen.getByRole('option', { name: 'Product' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Mari Contado' })).toBeInTheDocument();
  });

  it('sends only the filled filters to the server and reports how many are applied', async () => {
    render(<AuditTrail />);
    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole('option', { name: 'Record Created' })).toBeInTheDocument());

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'barcode' } });
    fireEvent.change(screen.getByLabelText('Action'), { target: { value: 'create' } });
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-09-01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));

    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalledTimes(2));
    expect(auditAPI.getTrail).toHaveBeenLastCalledWith({
      per_page: 200,
      search: 'barcode',
      action: 'create',
      date_from: '2026-09-01',
    });

    expect(await screen.findByText('3 filters applied')).toBeInTheDocument();
  });

  it('clears back to an unfiltered feed', async () => {
    render(<AuditTrail />);
    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalledTimes(1));

    expect(screen.getByRole('button', { name: 'Clear' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'ghost' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalledTimes(2));

    fireEvent.click(screen.getByRole('button', { name: 'Clear' }));

    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalledTimes(3));
    expect(auditAPI.getTrail).toHaveBeenLastCalledWith({ per_page: 200 });
    expect(screen.getByLabelText('Search')).toHaveValue('');
  });

  it('says the filters are the reason the feed is empty', async () => {
    render(<AuditTrail />);
    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalledTimes(1));

    auditAPI.getTrail.mockResolvedValue({ data: { data: [] } });
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'nothing-matches' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));

    expect(await screen.findByText('No activity records match these filters.')).toBeInTheDocument();
  });

  it('survives a filter-options endpoint that fails', async () => {
    auditAPI.getFilterOptions.mockRejectedValue(new Error('boom'));
    render(<AuditTrail />);

    await waitFor(() => expect(auditAPI.getTrail).toHaveBeenCalled());
    expect(screen.getByRole('option', { name: 'All actions' })).toBeInTheDocument();
    expect(screen.getByLabelText('Search')).toBeInTheDocument();
  });
});
