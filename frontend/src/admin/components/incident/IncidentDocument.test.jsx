import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import IncidentDocument from './IncidentDocument';
import {
  incidentReportsAPI, inventoryBatchesAPI, locationsAPI, productsAPI,
} from '../../services/api';

/**
 * The shell and its mode plumbing.
 *
 * The point of these is the rule the whole design turns on: **earlier sections
 * lock, they never hide.** An inspector must see what the reporter claimed —
 * that is half of what they are checking — and an admin must see both. A
 * regression that "tidied" a completed section off the screen would be
 * invisible to every other test in this repo.
 */

jest.mock('../../services/api', () => ({
  incidentReportsAPI: {
    file: jest.fn(), lookup: jest.fn(), setItemOutcome: jest.fn(),
    submitInspection: jest.fn(), addEvidence: jest.fn(), removeEvidence: jest.fn(),
    evidencePhotoBlob: jest.fn(), photoBlob: jest.fn(),
  },
  inventoryBatchesAPI: { getAll: jest.fn() },
  locationsAPI: { getAll: jest.fn() },
  productsAPI: { getAll: jest.fn() },
}));

// The barcode camera reaches for getUserMedia, which jsdom does not have.
jest.mock('../CameraScanner', () => () => null);
jest.mock('../CameraCapture', () => () => null);

// CRA's jest config resets mocks between tests, which wipes an implementation
// given in the factory above — so the fetches are armed per test.
beforeEach(() => {
  locationsAPI.getAll.mockResolvedValue({ data: [{ location_id: 1, location_name: 'Carmen' }] });
  productsAPI.getAll.mockResolvedValue({ data: [] });
  inventoryBatchesAPI.getAll.mockResolvedValue({ data: [] });
  incidentReportsAPI.evidencePhotoBlob.mockResolvedValue(new Blob(['x']));
  incidentReportsAPI.photoBlob.mockResolvedValue(new Blob(['x']));
  global.URL.createObjectURL = jest.fn(() => 'blob:fake-url');
  global.URL.revokeObjectURL = jest.fn();
});

const renderDoc = async (props = {}) => {
  const view = render(<IncidentDocument {...props} />);
  // The location fetch resolves after mount; awaiting it keeps the state
  // update inside act().
  await screen.findByRole('option', { name: 'Carmen' });
  return view;
};

// Each section is an aria-labelledby'd <section>, so it is addressable as a
// landmark by the very heading the reader sees.
const section = (name) => screen.getByRole('region', { name });

test('file mode opens the reporter half and greys the inspector half', async () => {
  await renderDoc();

  expect(section(/^Units$/)).toHaveClass('incdoc__section--editable');
  // Present, not absent: a reporter who can see the checklist is a reporter
  // who keeps the box instead of throwing it away.
  expect(section(/^Attachments$/)).toHaveClass('incdoc__section--awaiting');
  expect(section(/^Outcome per unit$/)).toHaveClass('incdoc__section--awaiting');
  expect(screen.getByRole('button', { name: /submit report/i })).toBeInTheDocument();
});

test('inspect mode locks the reporter half without hiding it', async () => {
  await renderDoc({ mode: 'inspect' });

  expect(section(/^Units$/)).toHaveClass('incdoc__section--readonly');
  expect(section(/^Dealer$/)).toHaveClass('incdoc__section--readonly');
  expect(section(/^Findings$/)).toHaveClass('incdoc__section--editable');
});

test('decide mode leaves every section on the page and none of it writable', async () => {
  await renderDoc({ mode: 'decide' });

  ['Dealer', 'Units', 'Problem summary', 'Attachments', 'Findings', 'Outcome per unit', 'Signatures']
    .forEach((title) => expect(section(new RegExp(`^${title}$`))).toHaveClass('incdoc__section--readonly'));
  // The admin decides; they never file.
  expect(screen.queryByRole('button', { name: /submit report/i })).not.toBeInTheDocument();
});

test('the document changes hands at a visible rule', async () => {
  await renderDoc();

  expect(screen.getByText(/everything below is the inspector/i)).toBeInTheDocument();
});

/**
 * A report mid-flight: filed, quarantined, waiting for someone to decide what
 * happens to the unit.
 */
const filedReport = (over = {}) => ({
  incident_id: 5,
  incident_number: 'INC-0005',
  reason: 'defective',
  status_name: 'Open',
  location_id: 1,
  narrative: 'the panel is cracked',
  dealer_name: 'Northgate Aircon',
  evidence: [],
  items: [{
    item_id: 11,
    product_id: 7,
    product_name: 'Split Type 1.5HP',
    status: 'quarantined',
    outcome: null,
    quantity: 1,
    unit_role: 'indoor',
    serial_number: 'SN-1',
    warranty_state: 'expired',
    allowed_outcomes: ['restock', 'return_to_supplier', 'repair', 'scrap', 'write_off'],
  }],
  ...over,
});

test('inspect mode shows the reporter’s answers, locked, in their own fields', async () => {
  await renderDoc({ mode: 'inspect', report: filedReport() });

  // Not summarised elsewhere — the same field, filled in and disabled.
  const dealer = screen.getByLabelText(/dealer name/i);
  expect(dealer).toHaveValue('Northgate Aircon');
  expect(dealer).toBeDisabled();
});

/**
 * The gate. The server serves `allowed_outcomes` per item, filtered by that
 * unit's warranty state and enforced with a 422 — a client that re-derived it
 * could only ever offer something the endpoint refuses.
 */
test('the outcome selector offers exactly what the server allowed', async () => {
  await renderDoc({ mode: 'inspect', report: filedReport() });

  const select = screen.getByLabelText(/what happens to this unit/i);
  const offered = Array.from(select.options).map((o) => o.value).filter(Boolean);
  expect(offered).toEqual(['restock', 'return_to_supplier', 'repair', 'scrap', 'write_off']);
  expect(offered).not.toContain('claim_warranty');
});

test('a warranty claim appears when the server says the unit is covered', async () => {
  await renderDoc({
    mode: 'inspect',
    report: filedReport({
      items: [{
        ...filedReport().items[0],
        warranty_state: 'in_warranty',
        allowed_outcomes: ['restock', 'return_to_supplier', 'claim_warranty', 'repair', 'scrap', 'write_off'],
      }],
    }),
  });

  const select = screen.getByLabelText(/what happens to this unit/i);
  expect(Array.from(select.options).map((o) => o.value)).toContain('claim_warranty');
});

// `expired` and `none` offer the same choices but are not the same fact: one
// unit was sold and its cover ran out, the other has not been sold at all.
test('expired and not-yet-sold are told apart on screen', async () => {
  const { unmount } = await renderDoc({ mode: 'inspect', report: filedReport() });
  expect(screen.getByText('Warranty expired')).toBeInTheDocument();
  unmount();

  await renderDoc({
    mode: 'inspect',
    report: filedReport({ items: [{ ...filedReport().items[0], warranty_state: 'none' }] }),
  });
  expect(screen.getByText('Not sold yet')).toBeInTheDocument();
});

// The second clock. Stock on the shelf reads "not sold yet" on the customer
// badge while the supplier still stands behind it, and only the supplier badge
// can tell an inspector whether a defective unit may go back.
test('supplier warranty is shown beside the customer one', async () => {
  await renderDoc({
    mode: 'inspect',
    report: filedReport({
      items: [{
        ...filedReport().items[0],
        warranty_state: 'none',
        supplier_warranty: {
          state: 'in_warranty',
          end_date: '2029-08-12',
          supplier_name: 'Supplier A',
          receiving_number: 'RCV-0044',
          po_number: 'PO-0064',
        },
      }],
    }),
  });

  expect(screen.getByText('Not sold yet')).toBeInTheDocument();
  expect(screen.getByText('Supplier warranty active')).toBeInTheDocument();
  expect(screen.getByText(/until 2029-08-12 · Supplier A · RCV-0044 \/ PO-0064/)).toBeInTheDocument();
});

// A disabled button with no explanation is a dead end: the inspector cannot
// see which requirement is outstanding, so they cannot clear it.
test('submitting an unfinished inspection says what is outstanding', async () => {
  await renderDoc({ mode: 'inspect', report: filedReport() });

  fireEvent.click(screen.getByRole('button', { name: /submit inspection/i }));

  expect(await screen.findByText(/still outstanding/i)).toBeInTheDocument();
  expect(screen.getByText('an outcome on 1 unit')).toBeInTheDocument();
  expect(screen.getByText(/picture of the defect —/i)).toBeInTheDocument();
});

/**
 * A report the inspector has finished with, waiting on the admin.
 *
 * The decide-mode test above asserts only on CSS classes, which is why four
 * empty sections shipped. These assert on what an admin can actually read.
 */
const inspectedReport = () => filedReport({
  status_name: 'Awaiting Inspection',
  inspection_findings: 'Compressor seized. Casing intact.',
  checked_by_asc: true,
  inspected_by_name: 'Rita Cruz',
  inspected_at: '2026-08-13T09:15:00+08:00',
  signed_by_representative: 'A. Reyes',
  signed_by_branch_manager: 'M. Santos',
  signed_at: '2026-08-13',
  evidence: [
    { evidence_id: 1, requirement: 'defect_photo', photo_path: 'incidents/a.jpg', photo_source: 'captured' },
    { evidence_id: 4, requirement: 'box_two_sides', photo_path: null, skipped_reason: 'the box was already discarded' },
  ],
  items: [{
    ...filedReport().items[0],
    outcome: 'scrap',
    outcome_note: 'compressor seized, casing intact',
  }],
});

test('decide mode shows the admin the whole inspection, not four empty sections', async () => {
  await renderDoc({ mode: 'decide', report: inspectedReport() });

  // Findings, and whose judgement this is.
  expect(screen.getByText(/Compressor seized\. Casing intact\./)).toBeInTheDocument();
  expect(screen.getByText(/Inspected by Rita Cruz/)).toBeInTheDocument();

  // The outcome that Confirm will apply, and that it moves stock.
  expect(screen.getByText('Scrap')).toBeInTheDocument();
  expect(screen.getByText(/Confirming deducts this unit from stock/i)).toBeInTheDocument();

  // The evidence, including why one requirement has no photo.
  expect(screen.getByText(/the box was already discarded/)).toBeInTheDocument();

  // The names on the hardcopy in their hand.
  expect(screen.getByText('A. Reyes')).toBeInTheDocument();
  expect(screen.getByText('M. Santos')).toBeInTheDocument();
});

test('decide mode gives the admin nothing to write in below the rule', async () => {
  await renderDoc({ mode: 'decide', report: inspectedReport() });

  // setOutcome is `inspect`-gated and decide is `resolve`-gated: a control here
  // would 403 rather than save.
  expect(screen.queryByLabelText(/what happens to this unit/i)).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /can.t provide this/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^remove$/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /submit inspection/i })).not.toBeInTheDocument();
});

/**
 * The worst case for a summary: a report the inspector opened and did nothing
 * to. Every block must say its absence in words — a blank one is exactly the
 * bug, and "nothing recorded" and "this screen failed to load it" must never
 * look the same to someone about to move stock.
 */
test('decide mode states an empty inspection in words rather than blank space', async () => {
  await renderDoc({
    mode: 'decide',
    report: filedReport({ evidence: [], items: [] }),
  });

  expect(screen.getByText(/no inspection has been submitted/i)).toBeInTheDocument();
  expect(screen.getByText(/names no physical units/i)).toBeInTheDocument();
  expect(screen.getAllByText(/not recorded/i).length).toBeGreaterThan(0);
  // The evidence checklist still lists what was never answered, rather than
  // collapsing to nothing and reading as "no photographs were required".
  expect(screen.getAllByText(/Nothing was attached against this requirement/i).length)
    .toBeGreaterThan(0);
});

test('file mode still greys the inspector half rather than summarising it', async () => {
  await renderDoc();

  // The reporter sees what WILL be asked, so they keep the box instead of
  // throwing it away. The ternary must not swallow this fallback.
  expect(screen.getByText(/Picture of the defect/i)).toBeInTheDocument();

  // The other three previews below the rule, for the same reason: all four
  // took their content as a boolean-gated child, so all four could vanish
  // together and the class-only test above would still have passed.
  expect(screen.getByText('Not inspected yet.')).toBeInTheDocument();
  expect(screen.getByText(/Unit 1 — awaiting inspection/)).toBeInTheDocument();
  expect(screen.getByText(/Branch manager — name and date/)).toBeInTheDocument();
});
