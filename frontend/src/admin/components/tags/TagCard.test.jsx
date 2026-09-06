import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import TagCard from './TagCard';

const minted = {
  serial_id: 1,
  serial_number: 'INT-PO-2026-237-01',
  tier: 'minted',
  product_name: 'Copper Tube',
  print_count: 0,
  last_printed_at: null,
};

test('it shows the code and the product', () => {
  render(<TagCard tag={minted} />);

  expect(screen.getByText('Copper Tube')).toBeInTheDocument();
});

/* The badge is the whole point of showing three tiers in one grid: a clerk
   must be able to tell whose identifier is on the box before reprinting it. */
test('it names which identity the code is', () => {
  const { rerender } = render(<TagCard tag={minted} />);
  expect(screen.getByText(/our tag/i)).toBeInTheDocument();

  rerender(<TagCard tag={{ ...minted, tier: 'supplier' }} />);
  expect(screen.getByText(/supplier code/i)).toBeInTheDocument();

  rerender(<TagCard tag={{ ...minted, tier: 'manufacturer' }} />);
  expect(screen.getByText(/manufacturer serial/i)).toBeInTheDocument();
});

test('it says whether the label has been sent to a printer', () => {
  const { rerender } = render(<TagCard tag={minted} />);
  expect(screen.getByText(/not yet sent/i)).toBeInTheDocument();

  rerender(<TagCard tag={{ ...minted, print_count: 3, last_printed_at: '2026-09-03T10:00:00+08:00' }} />);
  expect(screen.getByText(/sent 3/i)).toBeInTheDocument();
});

test('clicking the barcode asks to enlarge it', () => {
  const onZoom = jest.fn();
  render(<TagCard tag={minted} onZoom={onZoom} />);

  fireEvent.click(screen.getByRole('button', { name: /enlarge/i }));

  expect(onZoom).toHaveBeenCalledWith(minted);
});

/* Selection and reprint belong to the printing page. The per-product sheet
   renders the same card without them, so neither may be assumed. */
test('it renders without a checkbox or reprint action when none are offered', () => {
  render(<TagCard tag={minted} />);

  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /reprint/i })).not.toBeInTheDocument();
});

test('it offers selection and reprint when the host wants them', () => {
  const onToggle = jest.fn();
  const onReprint = jest.fn();

  render(<TagCard tag={minted} selected={false} onToggle={onToggle} onReprint={onReprint} />);

  fireEvent.click(screen.getByRole('checkbox'));
  expect(onToggle).toHaveBeenCalledWith(minted.serial_id);

  fireEvent.click(screen.getByRole('button', { name: /reprint/i }));
  expect(onReprint).toHaveBeenCalledWith(minted);
});

/* Which delivery and which shelf this code came from. A clerk printing
   replacements for one receipt needs to tell two cards apart, and the code
   itself does not say. Queue rows carry these; the per-product sheet's tags
   do not, which is why the block is gated on the row having a tier at all. */
test('it says which receipt and location the tag came from', () => {
  render(<TagCard tag={{ ...minted, receiving_number: 'RCV-1', receiving_date: '2026-09-01', location_name: 'Main' }} />);

  expect(screen.getByText(/RCV-1/)).toBeInTheDocument();
  expect(screen.getByText(/Main/)).toBeInTheDocument();
});

test('a card with no receipt says nothing about one', () => {
  render(<TagCard tag={{ serial_number: 'INT-1', product_name: 'Copper Tube' }} />);

  expect(screen.queryByText(/RCV-/)).not.toBeInTheDocument();
});
