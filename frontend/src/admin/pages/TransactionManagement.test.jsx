import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import TransactionManagement from './TransactionManagement';

import {
  receivingsAPI,
  issuancesAPI,
  transfersAPI,
  adjustmentsAPI,
  inventoryAPI,
  incidentReportsAPI,
  warrantyClaimsAPI,
  companySettingsAPI,
  openApiFile,
} from '../services/api';

// The page is only responsible for its own data assembly; the layout chrome
// pulls in auth context and a sidebar that have nothing to do with that.
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() },
}));

jest.mock('../services/api', () => ({
  receivingsAPI: { getAll: jest.fn(), getById: jest.fn() },
  issuancesAPI: { getAll: jest.fn(), getById: jest.fn(), cancel: jest.fn() },
  issuanceOrderAPI: { quote: jest.fn() },
  transfersAPI: { getAll: jest.fn(), getById: jest.fn() },
  adjustmentsAPI: { getAll: jest.fn(), getById: jest.fn() },
  customersAPI: { getById: jest.fn() },
  companySettingsAPI: { get: jest.fn() },
  inventoryAPI: { getAll: jest.fn() },
  incidentReportsAPI: { list: jest.fn() },
  warrantyClaimsAPI: { getAll: jest.fn(), getById: jest.fn() },
  openApiFile: jest.fn(),
}));

/**
 * Every fixture is dated well before "today" on purpose.
 *
 * Real movement history is mostly not from today — a receiving posted last week
 * is still a transaction. A page that only surfaces rows created in the last few
 * hours looks, to the person reading it, exactly like a page that stopped
 * recording those types at all.
 */
const DAYS_AGO = (n) => {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d.toISOString().slice(0, 10);
};

const page = (rows) => ({ data: rows, pagination: { last_page: 1, total: rows.length } });

beforeEach(() => {
  receivingsAPI.getAll.mockResolvedValue(page([{
    receiving_id: 60,
    receiving_number: 'GRN-20260905-000060',
    receiving_date: DAYS_AGO(9),
    total_quantity_received: 5,
    location: { location_name: 'Main Warehouse' },
    details: [],
  }]));

  issuancesAPI.getAll.mockResolvedValue(page([{
    issuance_id: 340,
    dr_number: 'DR-20260906-8F4EE',
    issuance_date: DAYS_AGO(8),
    total_amount: 100,
    location: { location_name: 'Main Warehouse' },
    status: { status_name: 'Completed' },
    details: [],
  }]));

  transfersAPI.getAll.mockResolvedValue(page([{
    transfer_id: 33,
    transfer_number: 'TR-20260905-0033',
    transfer_date: DAYS_AGO(7),
    total_quantity_transferred: 1,
    from_location: { location_name: 'Main Warehouse' },
    to_location: { location_name: 'Branch 2' },
    status: { status_name: 'Completed' },
    details: [],
  }]));

  adjustmentsAPI.getAll.mockResolvedValue(page([{
    adjustment_id: 12,
    adjustment_number: 'ADJ-20260904-0012',
    adjustment_date: DAYS_AGO(6),
    total_variance_negative: 2,
    location: { location_name: 'Main Warehouse' },
    status: { status_name: 'Completed' },
    details: [],
  }]));

  // The incident endpoint caps at 500 and returns a flat mapped payload — no
  // `pagination` key, and the fields are already flattened (location_name,
  // product_name) rather than nested relations.
  incidentReportsAPI.list.mockResolvedValue({
    data: [{
      incident_id: 7,
      incident_number: 'INC-20260903-0007',
      product_name: 'Split Type 1.5HP',
      location_name: 'Main Warehouse',
      reason: 'damaged',
      quantity: 3,
      occurred_at: `${DAYS_AGO(5)}T02:00:00+00:00`,
      status_name: 'Open',
      created_at: `${DAYS_AGO(5)} 02:00:00`,
    }],
  });

  warrantyClaimsAPI.getAll.mockResolvedValue(page([{
    claim_id: 21,
    claim_number: 'WC-20260902-0021',
    claim_type: 'replacement',
    serial_number: 'SN-ABC-001',
    parts_cost: '250.00',
    created_at: `${DAYS_AGO(4)} 09:00:00`,
    product: { product_name: 'Window Type 1.0HP' },
    location: { location_name: 'Branch 2' },
    status: { status_name: 'Pending' },
  }]));

  inventoryAPI.getAll.mockResolvedValue({ data: [] });
  companySettingsAPI.get.mockResolvedValue({ pdf_paper_size: 'a4' });
  // The caller chains .catch() onto this — a bare jest.fn() returns undefined
  // and the click throws before the assertion is ever reached.
  openApiFile.mockResolvedValue(undefined);
});

const renderPage = () => render(
  <MemoryRouter>
    <TransactionManagement />
  </MemoryRouter>,
);

test('the ledger opens on every movement type, not only what was recorded today', async () => {
  renderPage();

  await waitFor(() => {
    expect(screen.getByText(/GRN-20260905-000060/)).toBeInTheDocument();
  });

  expect(screen.getByText(/DR-20260906-8F4EE/)).toBeInTheDocument();
  expect(screen.getByText(/TR-20260905-0033/)).toBeInTheDocument();
  expect(screen.getByText(/ADJ-20260904-0012/)).toBeInTheDocument();
});

test('incident reports and warranty claims are movement types the ledger carries', async () => {
  renderPage();

  await waitFor(() => {
    expect(screen.getByText(/INC-20260903-0007/)).toBeInTheDocument();
  });

  expect(screen.getByText(/WC-20260902-0021/)).toBeInTheDocument();

  // Scoped to the row badges: 'Incident' and 'Warranty' also exist as <option>s
  // in the type filter, and matching those would pass without a single row.
  const badges = screen.getAllByText(/^(Incident|Warranty)$/)
    .filter((el) => el.className.includes('txn-type-badge'))
    .map((el) => el.textContent);
  expect(badges).toEqual(expect.arrayContaining(['Incident', 'Warranty']));
});

test('opening an incident row does not go looking for an adjustment', async () => {
  renderPage();
  await waitFor(() => expect(screen.getByText(/INC-20260903-0007/)).toBeInTheDocument());

  fireEvent.click(screen.getByText(/INC-20260903-0007/).closest('article'));

  await waitFor(() => {
    expect(screen.getByText(/Incident · INC-20260903-0007/)).toBeInTheDocument();
  });

  // The detail effect used to fall through to adjustmentsAPI for any
  // non-Issuance row, so an incident id was looked up as an adjustment id.
  expect(adjustmentsAPI.getById).not.toHaveBeenCalled();
  // Twice over: the ledger row and the detail table below it. What the detail
  // table contains is asserted separately.
  expect(screen.getAllByText(/Split Type 1.5HP/).length).toBeGreaterThan(0);
});

test('a resolved incident is finished, not still pending', async () => {
  incidentReportsAPI.list.mockResolvedValue({
    data: [{
      incident_id: 8,
      incident_number: 'INC-20260901-0008',
      product_name: 'Split Type 1.5HP',
      location_name: 'Main Warehouse',
      quantity: 1,
      occurred_at: `${DAYS_AGO(3)}T02:00:00+00:00`,
      status_name: 'Resolved',
    }],
  });

  renderPage();
  await waitFor(() => expect(screen.getByText(/INC-20260901-0008/)).toBeInTheDocument());

  const row = screen.getByText(/INC-20260901-0008/).closest('article');
  expect(within(row).getByText('Completed')).toBeInTheDocument();
});

test('a warranty claim prints its own document, not an adjustment', async () => {
  warrantyClaimsAPI.getById.mockResolvedValue({
    data: { claim_id: 21, claim_number: 'WC-20260902-0021', items: [] },
  });

  renderPage();
  await waitFor(() => expect(screen.getByText(/WC-20260902-0021/)).toBeInTheDocument());
  fireEvent.click(screen.getByText(/WC-20260902-0021/).closest('article'));

  await waitFor(() => expect(screen.getByText('Download PDF')).toBeInTheDocument());
  fireEvent.click(screen.getByText('Download PDF'));

  // The path builder's trailing else was `/adjustments/{id}/pdf`, so claim 21
  // would have been printed as adjustment 21 — a real, unrelated document.
  expect(openApiFile).toHaveBeenCalledWith(
    expect.stringContaining('/warranty-claims/21/receipt/pdf'),
    expect.anything(),
  );
});

test('an incident offers no document button, because it has no document', async () => {
  renderPage();
  await waitFor(() => expect(screen.getByText(/INC-20260903-0007/)).toBeInTheDocument());
  fireEvent.click(screen.getByText(/INC-20260903-0007/).closest('article'));

  await waitFor(() => expect(screen.getByText(/Incident · INC-20260903-0007/)).toBeInTheDocument());

  // There is no incident PDF endpoint. A button that silently does nothing is
  // worse than no button.
  expect(screen.queryByText('Download PDF')).not.toBeInTheDocument();
  expect(screen.queryByText('View Document')).not.toBeInTheDocument();
});

test('a warranty detail lists its claim items under warranty headings', async () => {
  warrantyClaimsAPI.getById.mockResolvedValue({
    data: {
      claim_id: 21,
      claim_number: 'WC-20260902-0021',
      items: [{
        detail_id: 5,
        product_name: 'Compressor Assembly',
        product_code: 'CMP-001',
        serial_number: 'SN-PART-9',
        quantity_requested: 2,
        quantity_issued: 1,
      }],
    },
  });

  renderPage();
  await waitFor(() => expect(screen.getByText(/WC-20260902-0021/)).toBeInTheDocument());
  fireEvent.click(screen.getByText(/WC-20260902-0021/).closest('article'));

  // The headers fell through to the adjustment set, so a claim's parts were
  // labelled Added / Deducted / Net change — and `items` was never read at all
  // because the builder only looked at `details`.
  await waitFor(() => expect(screen.getByText('Compressor Assembly')).toBeInTheDocument());
  expect(screen.getByText('Issued (pcs)')).toBeInTheDocument();
  expect(screen.queryByText('Net change')).not.toBeInTheDocument();
});

test('an incident detail states what was lost, not a stock adjustment', async () => {
  renderPage();
  await waitFor(() => expect(screen.getByText(/INC-20260903-0007/)).toBeInTheDocument());

  // Read the row before opening the modal — afterwards the reference appears in
  // the row, the modal title and the modal body, and the query is ambiguous.
  const row = screen.getByText(/INC-20260903-0007/).closest('article');
  const rowDate = row.querySelector('.txn-feed-meta').textContent.split('•')[1].trim();

  fireEvent.click(row);

  await waitFor(() => expect(screen.getByText(/Incident · INC-20260903-0007/)).toBeInTheDocument());

  expect(screen.getByText('Quantity (pcs)')).toBeInTheDocument();
  expect(screen.queryByText('Net change')).not.toBeInTheDocument();

  // The row is dated by when the incident HAPPENED. The modal read a chain of
  // receiving/transfer/adjustment dates then fell back to created_at, so the
  // same event could carry two different dates on the same screen.
  expect(screen.getByText('Transaction date').parentElement).toHaveTextContent(rowDate);
});

test('a source that fails is named rather than silently dropped', async () => {
  warrantyClaimsAPI.getAll.mockRejectedValue(new Error('403 Forbidden'));
  renderPage();

  await waitFor(() => {
    expect(screen.getByText(/Warranty claims could not be loaded/)).toBeInTheDocument();
  });

  // The other five still render — one dead source must not blank the ledger.
  expect(screen.getByText(/GRN-20260905-000060/)).toBeInTheDocument();
});
