import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import SearchSelect from './SearchSelect';

const items = [
  { id: 'a', label: 'Copper Tube', sublabel: 'CON-1', searchText: 'Copper Tube CON-1' },
  { id: 'b', label: 'Split Type AC', sublabel: 'APP-9', searchText: 'Split Type AC APP-9' },
];

const openMenu = () => fireEvent.focus(screen.getByPlaceholderText(/search/i));

test('it lists the items once opened', () => {
  render(<SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…" />);
  openMenu();

  expect(screen.getByText('Copper Tube')).toBeInTheDocument();
  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
});

/* searchText, not label: the caller decides what a row is findable by, which
   is what lets one control serve products, receipts and locations. */
test('the query matches searchText, not just the label', () => {
  render(<SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…" />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: 'APP-9' } });

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('picking a row reports its id', () => {
  const onChange = jest.fn();
  render(<SearchSelect items={items} value="" onChange={onChange} placeholder="Search…" />);
  openMenu();

  fireEvent.click(screen.getByText('Copper Tube'));

  expect(onChange).toHaveBeenCalledWith('a');
});

test("it shows the caller's empty text when nothing matches", () => {
  render(
    <SearchSelect items={items} value="" onChange={jest.fn()}
                  placeholder="Search…" emptyText="No matching receipts" />,
  );
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: 'zzzz' } });

  expect(screen.getByText('No matching receipts')).toBeInTheDocument();
});

test('a selected item shows its selectedLabel when closed', () => {
  const labelled = [{ ...items[0], selectedLabel: '[CON-1] Copper Tube' }];

  render(<SearchSelect items={labelled} value="a" onChange={jest.fn()} placeholder="Search…" />);

  expect(screen.getByDisplayValue('[CON-1] Copper Tube')).toBeInTheDocument();
});

test('without a selectedLabel it falls back to the label', () => {
  render(<SearchSelect items={items} value="a" onChange={jest.fn()} placeholder="Search…" />);

  expect(screen.getByDisplayValue('Copper Tube')).toBeInTheDocument();
});

/* The seam ProductSearchSelect hangs its category and brand dropdowns on. */
test('it renders the facet node above the input', () => {
  render(
    <SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…"
                  facets={<button type="button">All categories</button>} />,
  );

  expect(screen.getByRole('button', { name: /all categories/i })).toBeInTheDocument();
});

test('a disabled picker opens nothing', () => {
  render(<SearchSelect items={items} value="" onChange={jest.fn()} placeholder="Search…" disabled />);
  openMenu();

  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});
