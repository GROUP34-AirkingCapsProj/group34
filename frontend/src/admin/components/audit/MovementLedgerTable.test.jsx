import React from 'react';
import { render, screen } from '@testing-library/react';
import MovementLedgerTable from './MovementLedgerTable';

const original = {
  ledger_id: 1,
  occurred_at: '2026-09-10T09:00:00+08:00',
  posted_at: '2026-09-10T09:00:05+08:00',
  is_backdated: false,
  direction: 'out',
  movement_type: 'issuance',
  product_name: 'Copper Pipe',
  batch_id: 55,
  serial_number: null,
  quantity: 4,
  unit_cost: 100,
  extended_value: 400,
  cost_basis: 'actual',
  correlation_id: 'issuances:9',
  actor_name: 'Ana Cruz',
  reverses_ledger_id: null,
  origin: 'posted',
};

const reversal = {
  ...original,
  ledger_id: 2,
  direction: 'in',
  reverses_ledger_id: 1,
};

const noop = () => {};

describe('MovementLedgerTable', () => {
  it('renders a movement row with its frozen cost', () => {
    render(<MovementLedgerTable rows={[original]} onOpenEvidence={noop} />);

    expect(screen.getByText('Copper Pipe')).toBeInTheDocument();
    expect(screen.getByTestId('row-1')).toHaveTextContent('400.00');
  });

  it('strikes through BOTH the reversal and the row it reversed', () => {
    render(<MovementLedgerTable rows={[original, reversal]} onOpenEvidence={noop} />);

    // Both stay visible: an auditor needs to see that something was undone.
    expect(screen.getByTestId('row-1').className).toContain('ae-row--reversed');
    expect(screen.getByTestId('row-2').className).toContain('ae-row--reversed');
    expect(screen.getByTestId('row-2')).toHaveTextContent('Reversal');
  });

  it('flags a reconstructed row so derived history is never mistaken for evidence', () => {
    render(<MovementLedgerTable rows={[{ ...original, origin: 'reconstructed' }]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('row-1')).toHaveTextContent('Reconstructed');
  });

  it('flags a cost that is not an actual price', () => {
    render(
      <MovementLedgerTable rows={[{ ...original, cost_basis: 'derived_weighted_avg' }]} onOpenEvidence={noop} />
    );

    expect(screen.getByTestId('row-1')).toHaveTextContent('Derived');
  });

  it('shows an em dash rather than zero when a row carries no cost', () => {
    render(
      <MovementLedgerTable
        rows={[{ ...original, unit_cost: null, extended_value: null, cost_basis: 'unavailable' }]}
        onOpenEvidence={noop}
      />
    );

    const row = screen.getByTestId('row-1');
    expect(row).toHaveTextContent('No cost');
    // Zero would assert the stock was free; the absence of a figure is the truth.
    expect(row).not.toHaveTextContent('₱0.00');
  });

  it('flags a backdated posting', () => {
    render(<MovementLedgerTable rows={[{ ...original, is_backdated: true }]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('row-1')).toHaveTextContent('Backdated');
  });

  it('shows an empty state rather than an empty table', () => {
    render(<MovementLedgerTable rows={[]} onOpenEvidence={noop} />);

    expect(screen.getByTestId('movements-empty')).toBeInTheDocument();
  });
});
