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

/* Picking is only half a filter. Without a way back to "no selection" the
   box can be emptied on screen — focus blanks it — while the value behind it
   never changes, so the filter stays applied and the label returns on blur. */
test('it offers a way back to no selection when the caller gives one', () => {
  const onChange = jest.fn();
  render(
    <SearchSelect items={items} value="a" onChange={onChange}
                  placeholder="Search…" clearLabel="All products" />,
  );
  openMenu();

  fireEvent.click(screen.getByText('All products'));

  expect(onChange).toHaveBeenCalledWith('');
});

test('the way back is offered only once something is selected', () => {
  render(
    <SearchSelect items={items} value="" onChange={jest.fn()}
                  placeholder="Search…" clearLabel="All products" />,
  );
  openMenu();

  expect(screen.queryByText('All products')).not.toBeInTheDocument();
});

/* A query must not hide it: typing is how you get to the menu in the first
   place, and a filter you cannot find your way out of is the bug above. */
test('a query never hides the way back', () => {
  render(
    <SearchSelect items={items} value="a" onChange={jest.fn()}
                  placeholder="Search…" clearLabel="All products" />,
  );
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: 'zzzz' } });

  expect(screen.getByText('All products')).toBeInTheDocument();
});

/* The issuance picker passes no clearLabel and must keep exactly the rows it
   had — an extra row in it would be a change to a flow this never touched. */
test('without a clearLabel the menu holds only the items', () => {
  render(<SearchSelect items={items} value="a" onChange={jest.fn()} placeholder="Search…" />);
  openMenu();

  expect(screen.getAllByRole('button')).toHaveLength(items.length);
});

/* Emptying a search box is how everyone clears a search box. The menu row is
   not enough on its own: the control looks like a text input, so deleting its
   contents has to mean what it looks like it means. */
test('emptying the box clears the value when the picker is clearable', () => {
  const onChange = jest.fn();
  render(
    <SearchSelect items={items} value="a" onChange={onChange}
                  placeholder="Search…" clearLabel="All products" />,
  );
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: '' } });

  expect(onChange).toHaveBeenCalledWith('');
});

/* Focus blanks the query so you can type over a selection. That is not the
   user clearing anything, and dropping the filter on focus would mean the
   value could not survive being looked at. */
test('merely focusing the box changes nothing', () => {
  const onChange = jest.fn();
  render(
    <SearchSelect items={items} value="a" onChange={onChange}
                  placeholder="Search…" clearLabel="All products" />,
  );
  openMenu();

  expect(onChange).not.toHaveBeenCalled();
});

/* A picker whose value is required — the issuance line item — has no empty
   state to fall back to, so an empty box is a query, not a clearing. */
test('emptying the box leaves a required picker alone', () => {
  const onChange = jest.fn();
  render(<SearchSelect items={items} value="a" onChange={onChange} placeholder="Search…" />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search/i), { target: { value: '' } });

  expect(onChange).not.toHaveBeenCalled();
});

/* The original bug in one line. Focusing used to blank the box, so a filter
   that was still applied looked cleared — and clicking away brought the label
   back, which is what "I cleared it and it did not clear" was. */
test('focusing a picker keeps showing what is filtered', () => {
  render(
    <SearchSelect items={items} value="a" onChange={jest.fn()}
                  placeholder="Search…" clearLabel="All products" />,
  );
  openMenu();

  expect(screen.getByDisplayValue('Copper Tube')).toBeInTheDocument();
});

/* ...and the menu still offers everything, so showing the selection does not
   cost you the ability to browse to another one. */
test('the open menu lists every item even while the box shows a selection', () => {
  render(<SearchSelect items={items} value="a" onChange={jest.fn()} placeholder="Search…" />);
  openMenu();

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
});
