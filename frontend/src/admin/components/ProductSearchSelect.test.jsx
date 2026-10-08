import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import ProductSearchSelect from './ProductSearchSelect';

/* The normalized shape every caller maps into — see OrderBuilder.jsx:517. */
const products = [
  {
    id: '1', name: 'Copper Tube', code: 'CON-1',
    categoryId: 'c1', categoryName: 'Materials', brandId: 'b1', brandName: 'Acme',
  },
  {
    id: '2', name: 'Split Type AC', code: 'APP-9',
    categoryId: 'c2', categoryName: 'Appliances', brandId: 'b2', brandName: 'Zephyr',
  },
];

/* The menu only exists once the input has focus. */
const openMenu = () => fireEvent.focus(screen.getByPlaceholderText(/search product/i));

test('it lists the products once opened', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  expect(screen.getByText('Copper Tube')).toBeInTheDocument();
  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
});

/* A picker who knows the code but not the display name is the reason the
   search reads more than product_name. */
test('typing a product code finds the product', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search product/i), { target: { value: 'APP-9' } });

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('typing a brand finds the product too', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search product/i), { target: { value: 'Zephyr' } });

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('picking a row reports that product id', () => {
  const onChange = jest.fn();
  render(<ProductSearchSelect products={products} value="" onChange={onChange} />);
  openMenu();

  fireEvent.click(screen.getByText('Copper Tube'));

  expect(onChange).toHaveBeenCalledWith('1');
});

test('the category filter narrows the list', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);

  fireEvent.change(screen.getByTitle(/filter products by category/i), { target: { value: 'c2' } });
  openMenu();

  expect(screen.getByText('Split Type AC')).toBeInTheDocument();
  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});

test('it says so when nothing matches', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} />);
  openMenu();

  fireEvent.change(screen.getByPlaceholderText(/search product/i), { target: { value: 'zzzz' } });

  expect(screen.getByText(/no matching products/i)).toBeInTheDocument();
});

/* Closed, the box shows what is selected — not an empty search field, which
   would read as "nothing chosen". */
test('a selected product shows its code and name when the menu is closed', () => {
  render(<ProductSearchSelect products={products} value="1" onChange={jest.fn()} />);

  expect(screen.getByDisplayValue('[CON-1] Copper Tube')).toBeInTheDocument();
});

test('a disabled picker opens nothing', () => {
  render(<ProductSearchSelect products={products} value="" onChange={jest.fn()} disabled />);
  openMenu();

  expect(screen.queryByText('Copper Tube')).not.toBeInTheDocument();
});
