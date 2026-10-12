import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useAuditEvidence } from './useAuditEvidence';

jest.mock('../services/api', () => ({
  auditEvidenceAPI: {
    getSummary: jest.fn(() => Promise.resolve({ data: { quantity_in: 10, value_in: 1000 } })),
  },
}));

const { auditEvidenceAPI } = require('../services/api');

function Probe() {
  const { scope, setScope, summary, loading, error } = useAuditEvidence();

  return (
    <div>
      <span data-testid="from">{scope.from}</span>
      <span data-testid="to">{scope.to}</span>
      <span data-testid="lens">{scope.lens}</span>
      <span data-testid="qty">{summary ? summary.quantity_in : '—'}</span>
      <span data-testid="loading">{loading ? 'yes' : 'no'}</span>
      <span data-testid="error">{error || ''}</span>
      <button onClick={() => setScope({ lens: 'finance' })}>finance</button>
    </div>
  );
}

const renderProbe = (initialPath = '/audit-evidence') =>
  render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Probe />
    </MemoryRouter>
  );

describe('useAuditEvidence', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    auditEvidenceAPI.getSummary.mockResolvedValue({ data: { quantity_in: 10, value_in: 1000 } });
  });

  it('defaults to the inventory lens and the current month', async () => {
    renderProbe();

    await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('no'));

    expect(screen.getByTestId('lens')).toHaveTextContent('inventory');
    expect(screen.getByTestId('from')).not.toHaveTextContent('');
    expect(screen.getByTestId('qty')).toHaveTextContent('10');
  });

  it('reads the scope out of the URL so a shared link opens the same evidence', async () => {
    renderProbe('/audit-evidence?from=2026-03-01&to=2026-03-31&lens=finance');

    await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('no'));

    expect(screen.getByTestId('from')).toHaveTextContent('2026-03-01');
    expect(screen.getByTestId('to')).toHaveTextContent('2026-03-31');
    expect(screen.getByTestId('lens')).toHaveTextContent('finance');
  });

  it('writes a scope change back to the URL and refetches with it', async () => {
    renderProbe();

    await waitFor(() => expect(auditEvidenceAPI.getSummary).toHaveBeenCalled());

    fireEvent.click(screen.getByRole('button', { name: /finance/i }));

    await waitFor(() =>
      expect(auditEvidenceAPI.getSummary).toHaveBeenLastCalledWith(
        expect.objectContaining({ lens: 'finance' })
      )
    );
    expect(screen.getByTestId('lens')).toHaveTextContent('finance');
  });

  it('surfaces a server refusal instead of showing an empty period as zero activity', async () => {
    auditEvidenceAPI.getSummary.mockRejectedValueOnce(
      new Error('That period is wider than the 366 days this view can total at once.')
    );

    renderProbe();

    await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('366 days'));
    // Crucially not '0' — a refused query must not read as a quiet period.
    expect(screen.getByTestId('qty')).toHaveTextContent('—');
  });
});
