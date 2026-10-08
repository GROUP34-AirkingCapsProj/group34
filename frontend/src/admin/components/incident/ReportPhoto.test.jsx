import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import ReportPhoto from './ReportPhoto';
import { incidentReportsAPI } from '../../services/api';

/**
 * The one photo the REPORTER attached at filing.
 *
 * It behaves like the inspector's evidence photos on purpose: an admin who
 * learns to click one to enlarge it will click the other, and two kinds of
 * photo on one document behaving differently is a small trap.
 */

jest.mock('../../services/api', () => ({
  incidentReportsAPI: { photoBlob: jest.fn() },
}));

// jsdom has no object-URL plumbing. CRA's Jest resets mocks between tests, so
// the resolved value is armed here rather than in the factory.
beforeEach(() => {
  global.URL.createObjectURL = jest.fn(() => 'blob:fake-url');
  global.URL.revokeObjectURL = jest.fn();
  incidentReportsAPI.photoBlob.mockResolvedValue(new Blob(['x']));
});

test('the reporter’s photo opens full-size like the evidence does', async () => {
  render(<ReportPhoto incidentId={5} photoPath="incidents/2026/08/abc.jpg" photoSource="captured" />);

  fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));

  const dialog = await screen.findByRole('dialog');
  // The overlay reuses the object URL already fetched — no second request.
  expect(dialog).toBeInTheDocument();
  expect(incidentReportsAPI.photoBlob).toHaveBeenCalledTimes(1);
  expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
});

test('closes on Escape without disturbing anything underneath', async () => {
  render(<ReportPhoto incidentId={5} photoPath="incidents/2026/08/abc.jpg" photoSource="captured" />);

  fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));
  expect(await screen.findByRole('dialog')).toBeInTheDocument();

  fireEvent.keyDown(document, { key: 'Escape' });

  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
});

// An absence stated in words. A blank space here reads identically to a photo
// that failed to load, and the two mean very different things to someone about
// to approve a write-off.
test('says so when nothing was attached at filing', () => {
  render(<ReportPhoto incidentId={5} photoPath={null} />);

  expect(screen.getByText(/No photo was attached at filing/i)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /enlarge/i })).not.toBeInTheDocument();
});

test('a photo that failed to load is not clickable', async () => {
  incidentReportsAPI.photoBlob.mockRejectedValue(new Error('403'));

  render(<ReportPhoto incidentId={5} photoPath="incidents/2026/08/abc.jpg" photoSource="captured" />);

  expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /enlarge/i })).not.toBeInTheDocument();
});

// Provenance, on the reporter's photo as much as on the inspector's evidence.
test('says whether the app watched the photo being taken', async () => {
  render(<ReportPhoto incidentId={5} photoPath="incidents/2026/08/abc.jpg" photoSource="uploaded" />);

  await screen.findByRole('button', { name: /enlarge/i });

  expect(screen.getByText('Uploaded from device')).toBeInTheDocument();
  expect(screen.queryByText('Taken in-app')).not.toBeInTheDocument();
});

// Reports filed before the camera existed do not know how their photo arrived,
// and "unknown" would be a claim the data cannot support.
test('a photo of unknown provenance claims no provenance at all', async () => {
  render(<ReportPhoto incidentId={5} photoPath="incidents/2026/08/abc.jpg" />);

  await screen.findByRole('button', { name: /enlarge/i });

  expect(screen.queryByText('Taken in-app')).not.toBeInTheDocument();
  expect(screen.queryByText('Uploaded from device')).not.toBeInTheDocument();
  expect(screen.queryByText(/unknown/i)).not.toBeInTheDocument();
});
