import React from 'react';
import { render, screen } from '@testing-library/react';
import ReconciliationPanel from './ReconciliationPanel';

const balanced = {
  opening_quantity: 70,
  quantity_in: 50,
  quantity_out: 20,
  closing_quantity: 100,
  opening_value: 7000,
  value_in: 5000,
  value_out: 2000,
  closing_value: 10000,
  stock_on_hand: 100,
  matches_stock: true,
  stock_variance: 0,
  unvalued_rows: 0,
  value_provable: true,
};

const noop = () => {};

describe('ReconciliationPanel', () => {
  it('shows the arithmetic so the closing figure can be checked by eye', () => {
    render(<ReconciliationPanel data={balanced} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-opening-qty')).toHaveTextContent('70');
    expect(screen.getByTestId('recon-in-qty')).toHaveTextContent('50');
    expect(screen.getByTestId('recon-out-qty')).toHaveTextContent('20');
    expect(screen.getByTestId('recon-closing-qty')).toHaveTextContent('100');
  });

  it('confirms the closing figure against live stock when it can', () => {
    render(<ReconciliationPanel data={balanced} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-matches')).toHaveTextContent('matches stock on hand');
  });

  it('reports a variance against live stock as something to investigate', () => {
    render(
      <ReconciliationPanel
        data={{ ...balanced, stock_on_hand: 94, matches_stock: false, stock_variance: 6 }}
        onDrillDown={noop}
      />
    );

    const warn = screen.getByTestId('recon-variance');
    expect(warn).toHaveTextContent('94');
    expect(warn).toHaveTextContent('moved without being recorded');
  });

  it('declines to claim a stock check when the scope is too wide for one', () => {
    render(
      <ReconciliationPanel
        data={{ ...balanced, stock_on_hand: null, matches_stock: null, stock_variance: null }}
        onDrillDown={noop}
      />
    );

    // No reassuring tick it cannot justify.
    expect(screen.getByTestId('recon-no-stock-check')).toBeInTheDocument();
    expect(screen.queryByTestId('recon-matches')).toBeNull();
  });

  it('says plainly when value cannot be fully proven', () => {
    render(<ReconciliationPanel data={{ ...balanced, unvalued_rows: 4 }} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-unvalued')).toHaveTextContent('4');
  });

  it('prompts for a scope when it has no data yet', () => {
    render(<ReconciliationPanel data={null} onDrillDown={noop} />);

    expect(screen.getByTestId('recon-empty')).toBeInTheDocument();
  });
});
