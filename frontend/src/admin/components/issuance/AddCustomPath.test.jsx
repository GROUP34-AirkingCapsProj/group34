import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AddCustomPath } from './OrderBuilder';
import { productsAPI, categoriesAPI, brandsAPI } from '../../services/api';

/**
 * The add-custom picker filters client-side, so whatever it fails to fetch is
 * unreachable — and a product that has stock in inventory but cannot be picked
 * reads to the user as issuance ignoring inventory entirely. This covers the
 * rule that prevents it: the picker loads EVERY page, not just the first.
 *
 * The real productsAPI.getAllPages runs here (only the transport getAll is
 * stubbed), so the paging walk itself is what is under test.
 */
jest.mock('../../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
}));

// 250 products over two pages of 200. The one with stock sits on page 2 — the
// position that used to make it invisible.
const PRODUCTS = Array.from({ length: 250 }, (_, i) => ({
  product_id: i + 1,
  product_name: i === 240 ? 'LG Dual Inverter 1HP Aircon' : `Product ${i + 1}`,
  product_code: `P-${i + 1}`,
  category_id: 3,
  category_name: 'Aircon',
  brand_id: 9,
  brand_name: 'LG',
}));

const setupMocks = () => {
  jest.spyOn(categoriesAPI, 'getAll').mockResolvedValue({ data: [] });
  jest.spyOn(brandsAPI, 'getAll').mockResolvedValue({ data: [] });
  jest.spyOn(productsAPI, 'getAll').mockImplementation(
    async ({ page = 1, per_page: perPage = 200 } = {}) => ({
      data: PRODUCTS.slice((page - 1) * perPage, page * perPage),
      pagination: {
        current_page: page,
        last_page: Math.ceil(PRODUCTS.length / perPage),
        total: PRODUCTS.length,
      },
    }),
  );
};

afterEach(() => jest.restoreAllMocks());

test('a product past the first page is still pickable, and adds by its own id', async () => {
  setupMocks();
  const onAdd = jest.fn().mockResolvedValue(undefined);

  render(<AddCustomPath disabled={false} onAdd={onAdd} />);

  await waitFor(() => expect(productsAPI.getAll).toHaveBeenCalledTimes(2));

  const search = await screen.findByPlaceholderText('Search product…');
  await userEvent.click(search);
  await userEvent.type(search, 'LG Dual Inverter');

  await userEvent.click(await screen.findByText('LG Dual Inverter 1HP Aircon'));
  await userEvent.click(screen.getByRole('button', { name: /add item/i }));

  await waitFor(() => expect(onAdd).toHaveBeenCalledWith(241, 1));
});
