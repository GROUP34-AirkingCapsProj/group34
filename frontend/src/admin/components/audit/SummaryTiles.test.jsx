import React from 'react';
import { render, screen } from '@testing-library/react';
import SummaryTiles from './SummaryTiles';

const summary = {
  quantity_in: 120,
  quantity_out: 45,
  net_quantity: 75,
  value_in: 24000,
  value_out: 9000,
  net_value: 15000,
  event_count: 18,
  row_count: 31,
  reversal_count: 2,
  unvalued_rows: 3,
  cost_basis: {
    actual: 20000,
    product_default: 4000,
    derived_weighted_avg: 9000,
    unavailable: 0,
  },
};

describe('SummaryTiles', () => {
  it('shows quantity and value movement for the period', () => {
    render(<SummaryTiles summary={summary} />);

    expect(screen.getByTestId('tile-quantity-in')).toHaveTextContent('120');
    expect(screen.getByTestId('tile-quantity-out')).toHaveTextContent('45');
    expect(screen.getByTestId('tile-value-in')).toHaveTextContent('24,000');
    expect(screen.getByTestId('tile-value-out')).toHaveTextContent('9,000');
  });

  it('shows what the value rests on, so evidence and reconstruction are distinguishable', () => {
    render(<SummaryTiles summary={summary} />);

    const basis = screen.getByTestId('cost-basis-breakdown');
    expect(basis).toHaveTextContent('Actual');
    expect(basis).toHaveTextContent('Derived average');
    expect(basis).toHaveTextContent('Product list cost');
    // A zero bucket is omitted rather than listed as an empty claim.
    expect(basis).not.toHaveTextContent('No cost recorded');
  });

  it('warns how many rows have a quantity but no recoverable cost', () => {
    render(<SummaryTiles summary={summary} />);

    expect(screen.getByTestId('unvalued-warning')).toHaveTextContent('3');
  });

  it('says nothing about unvalued rows when every row carries a cost', () => {
    render(<SummaryTiles summary={{ ...summary, unvalued_rows: 0 }} />);

    expect(screen.queryByTestId('unvalued-warning')).toBeNull();
  });

  it('notes reversals so a netted total is not mistaken for quiet activity', () => {
    render(<SummaryTiles summary={summary} />);

    expect(screen.getByTestId('reversal-note')).toHaveTextContent('2');
  });

  it('distinguishes an empty period from an unshipped movement type', () => {
    const { rerender } = render(<SummaryTiles summary={null} emptyReason="no-movements" />);
    expect(screen.getByTestId('summary-empty')).toHaveTextContent('No movements');

    rerender(<SummaryTiles summary={null} emptyReason="not-posted-yet" />);
    expect(screen.getByTestId('summary-empty')).toHaveTextContent('not recorded in the ledger yet');
  });
});
