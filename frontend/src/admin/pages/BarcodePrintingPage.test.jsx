import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import BarcodePrintingPage from './BarcodePrintingPage';
import { barcodeLabelsAPI } from '../services/api';
import { resetLabelPrinterStatus } from '../hooks/useLabelPrinter';

/* AdminLayout and useUserLocation both read AuthContext, which throws outside
   a provider. Stubbing them keeps every case below about the queue rather
   than about signing a user in — the house pattern, see SystemReports.test. */
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ user: {}, can: () => true, permissions: [] }),
  useUserLocation: () => ({ allowedLocations: [], isStrictLocation: false, locationId: null }),
}));

jest.mock('../services/api', () => ({
  barcodeLabelsAPI: {
    status: jest.fn(),
    queue: jest.fn(),
    queueIds: jest.fn(),
    print: jest.fn(),
  },
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() },
}));

const row = (overrides = {}) => ({
  serial_id: 1,
  serial_number: 'INT-PO-2026-237-01',
  supplier_barcode: null,
  tier: 'minted',
  bulk_printable: true,
  product_id: 5,
  product_name: 'Copper Tube',
  product_type: 'installation_material',
  location_name: 'Main',
  receiving_number: 'RCV-1',
  receiving_date: '2026-09-01',
  print_count: 0,
  last_printed_at: null,
  ...overrides,
});

const queueResponse = (rows) => ({
  data: rows,
  pagination: { current_page: 1, last_page: 1, per_page: 24, total: rows.length },
  counts: { total: rows.length, unsent: rows.length },
});

const renderPage = () => render(
  <MemoryRouter>
    <BarcodePrintingPage />
  </MemoryRouter>
);

beforeEach(() => {
  jest.clearAllMocks();
  resetLabelPrinterStatus();
  barcodeLabelsAPI.status.mockResolvedValue({ data: { configured: true } });
  barcodeLabelsAPI.queue.mockResolvedValue(queueResponse([row()]));
});

test('it loads the queue and shows the tags', async () => {
  renderPage();

  expect(await screen.findByText('Copper Tube')).toBeInTheDocument();
  expect(screen.getByText(/our tag/i)).toBeInTheDocument();
});

test('changing a filter re-queries', async () => {
  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.change(screen.getByLabelText(/product type/i), {
    target: { value: 'appliance' },
  });

  await waitFor(() => {
    expect(barcodeLabelsAPI.queue).toHaveBeenLastCalledWith(
      expect.objectContaining({ product_type: 'appliance' }),
      1,
      expect.any(Number),
    );
  });
});

/* The count the bar shows must be the count the server will actually print,
   which is why bulk_printable comes from the server rather than being
   guessed from the tier string in the browser. */
test('it warns when the selection holds codes bulk cannot print', async () => {
  barcodeLabelsAPI.queue.mockResolvedValue(queueResponse([
    row(),
    row({ serial_id: 2, serial_number: 'SUP-9911', tier: 'supplier', bulk_printable: false }),
  ]));

  renderPage();
  await screen.findAllByText('Copper Tube');

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(screen.getByLabelText('Select SUP-9911'));

  expect(await screen.findByText(/1 are supplier or manufacturer codes/i)).toBeInTheDocument();
});

test('sending a selection prints in bulk mode', async () => {
  barcodeLabelsAPI.print.mockResolvedValue({ message: 'Sent.' });

  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(await screen.findByRole('button', { name: /^print tags$/i }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.print).toHaveBeenCalledWith([1], 'bulk');
  });
});

/* The whole reason single mode exists: one damaged supplier label, replaced
   deliberately, without a bulk run putting a second sticker on every carton
   in the delivery. */
test('reprinting one card uses single mode', async () => {
  barcodeLabelsAPI.queue.mockResolvedValue(queueResponse([
    row({ serial_id: 2, serial_number: 'SUP-9911', tier: 'supplier', bulk_printable: false }),
  ]));
  barcodeLabelsAPI.print.mockResolvedValue({ message: 'Sent.' });

  renderPage();
  await screen.findByText(/supplier code/i);

  fireEvent.click(screen.getByRole('button', { name: /reprint this label/i }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.print).toHaveBeenCalledWith([2], 'single');
  });
});

test('select all matching asks the server for the ids', async () => {
  barcodeLabelsAPI.queueIds.mockResolvedValue({
    data: { serial_ids: [1, 2, 3], total: 3, truncated: false, max: 200 },
  });

  renderPage();
  await screen.findByText('Copper Tube');

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(screen.getByRole('button', { name: /select all matching/i }));

  expect(await screen.findByText(/3 selected/i)).toBeInTheDocument();
});

/* Print state is the reason half this page exists. Leaving the grid stale
   after a send means the cards still read "Not yet sent" for labels that are
   coming out of the printer, and an `unsent` filter still lists them. */
test('a successful send refreshes the print state on screen', async () => {
  barcodeLabelsAPI.print.mockResolvedValue({ message: 'Sent.' });

  renderPage();
  await screen.findByText('Copper Tube');

  const before = barcodeLabelsAPI.queue.mock.calls.length;

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(await screen.findByRole('button', { name: /^print tags$/i }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.queue.mock.calls.length).toBeGreaterThan(before);
  });
});

test('a failed send leaves the grid alone', async () => {
  barcodeLabelsAPI.print.mockRejectedValue(new Error('printer offline'));

  renderPage();
  await screen.findByText('Copper Tube');

  const before = barcodeLabelsAPI.queue.mock.calls.length;

  fireEvent.click(screen.getByLabelText('Select INT-PO-2026-237-01'));
  fireEvent.click(await screen.findByRole('button', { name: /^print tags$/i }));

  await waitFor(() => {
    expect(barcodeLabelsAPI.print).toHaveBeenCalled();
  });

  expect(barcodeLabelsAPI.queue.mock.calls.length).toBe(before);
});
