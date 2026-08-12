import React from 'react';
import { render, screen } from '@testing-library/react';
import IncidentDocument from './IncidentDocument';
import { inventoryBatchesAPI, locationsAPI, productsAPI } from '../../services/api';

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
  incidentReportsAPI: { file: jest.fn(), lookup: jest.fn() },
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
