import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import IncidentInspectionPage from './IncidentInspectionPage';
import {
  incidentReportsAPI, inventoryBatchesAPI, locationsAPI, productsAPI,
} from '../services/api';

/**
 * The inspector's queue.
 *
 * Submitting an inspection used to look like it did nothing: the report stayed
 * selected after it had left the queue, so the same document re-rendered in
 * `inspect` mode with a live Submit button — and, once the new status arrived,
 * wearing a "Sent back" badge that meant the opposite of what had just
 * happened. The only visible change was one word in the subtitle.
 *
 * These render the REAL IncidentDocument, so the Submit button clicked here is
 * the one the inspector clicks and `onSubmitted` is reached the way the app
 * reaches it.
 */

jest.mock('../services/api', () => ({
  incidentReportsAPI: {
    list: jest.fn(), get: jest.fn(), file: jest.fn(), lookup: jest.fn(),
    setItemOutcome: jest.fn(), submitInspection: jest.fn(), addEvidence: jest.fn(),
    removeEvidence: jest.fn(), evidencePhotoBlob: jest.fn(), photoBlob: jest.fn(),
  },
  inventoryBatchesAPI: { getAll: jest.fn() },
  locationsAPI: { getAll: jest.fn() },
  productsAPI: { getAll: jest.fn() },
}));

jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../context/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }));

// The barcode camera reaches for getUserMedia, which jsdom does not have.
jest.mock('../components/CameraScanner', () => () => null);
jest.mock('../components/CameraCapture', () => () => null);

/**
 * `wrong_type` asks for one photograph and nothing else, and the report carries
 * no undecided units — so the Submit button is genuinely unblocked and the
 * click reaches the server call rather than the blockers list.
 */
const queued = {
  incident_id: 5,
  incident_number: 'INC-0005',
  reason: 'wrong_type',
  status_name: 'Open',
  location_id: 1,
  location_name: 'Carmen',
};

const full = {
  ...queued,
  items: [],
  evidence: [{ evidence_id: 1, requirement: 'model_serial_sticker', note: 'attached' }],
};

const other = {
  incident_id: 9,
  incident_number: 'INC-0009',
  reason: 'wrong_type',
  status_name: 'Open',
  location_name: 'Carmen',
};

beforeEach(() => {
  incidentReportsAPI.list.mockResolvedValue({ data: [queued] });
  incidentReportsAPI.get.mockResolvedValue({ data: full });
  incidentReportsAPI.submitInspection.mockResolvedValue({ data: { ok: true } });
  incidentReportsAPI.evidencePhotoBlob.mockResolvedValue(new Blob(['x']));
  incidentReportsAPI.photoBlob.mockResolvedValue(new Blob(['x']));
  locationsAPI.getAll.mockResolvedValue({ data: [{ location_id: 1, location_name: 'Carmen' }] });
  productsAPI.getAll.mockResolvedValue({ data: [] });
  inventoryBatchesAPI.getAll.mockResolvedValue({ data: [] });
  global.URL.createObjectURL = jest.fn(() => 'blob:fake-url');
  global.URL.revokeObjectURL = jest.fn();
  // The success toast is SweetAlert2, which reads matchMedia; jsdom has none,
  // and the TypeError would land in the submit handler's catch and swallow the
  // onSubmitted call this whole file is about.
  window.matchMedia = jest.fn().mockReturnValue({
    matches: false, addEventListener: jest.fn(), removeEventListener: jest.fn(),
    addListener: jest.fn(), removeListener: jest.fn(),
  });
});

/** Select INC-0005 and wait for its document to be on screen. */
const openTheReport = async () => {
  fireEvent.click(await screen.findByText('INC-0005'));
  return screen.findByRole('button', { name: /submit inspection/i });
};

test('a report waiting to be inspected is offered in the queue', async () => {
  render(<IncidentInspectionPage />);

  expect(await screen.findByText('INC-0005')).toBeInTheDocument();
});

test('submitting clears the report off the screen once it has left the queue', async () => {
  render(<IncidentInspectionPage />);
  const submit = await openTheReport();

  // Submitting hands the report to the ADMIN, so the refetched queue no longer
  // has it.
  incidentReportsAPI.list.mockResolvedValue({ data: [] });
  fireEvent.click(submit);

  await waitFor(() => expect(incidentReportsAPI.submitInspection).toHaveBeenCalled());

  // The document is gone, and with it the Submit button that made a submitted
  // report look re-submittable.
  await waitFor(() => {
    expect(screen.queryByRole('button', { name: /submit inspection/i })).not.toBeInTheDocument();
  });
  expect(screen.queryByText('INC-0005')).not.toBeInTheDocument();
  expect(screen.getByText(/choose a report on the left/i)).toBeInTheDocument();
});

test('a report still in the queue keeps its place across a refetch', async () => {
  render(<IncidentInspectionPage />);
  const submit = await openTheReport();

  // A refetch that still lists the report — a second one arrived, say. Losing
  // the selection here would throw the inspector out of the document they are
  // part-way through on every reload.
  incidentReportsAPI.list.mockResolvedValue({ data: [queued, other] });
  fireEvent.click(submit);

  expect(await screen.findByText('INC-0009')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /submit inspection/i })).toBeInTheDocument();
});
