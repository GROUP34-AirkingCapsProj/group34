import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { EvidenceThumbnail } from './EvidencePhoto';
import { incidentReportsAPI } from '../../services/api';

/**
 * The admin's read-only half of the incident document.
 *
 * These assert on CONTENT, never on CSS classes. The decide-mode test in
 * IncidentDocument.test.jsx passed for months against four completely empty
 * sections because it only checked that each section carried the readonly
 * class — which is exactly how an admin came to be asked to confirm a stock
 * movement they could not see.
 */

jest.mock('../../services/api', () => ({
  incidentReportsAPI: {
    evidencePhotoBlob: jest.fn(),
    photoBlob: jest.fn(),
  },
}));

// jsdom has no object-URL plumbing.
beforeEach(() => {
  global.URL.createObjectURL = jest.fn(() => 'blob:fake-url');
  global.URL.revokeObjectURL = jest.fn();
  incidentReportsAPI.evidencePhotoBlob.mockResolvedValue(new Blob(['x']));
  incidentReportsAPI.photoBlob.mockResolvedValue(new Blob(['x']));
});

describe('EvidenceThumbnail', () => {
  test('opens the photo full-size without fetching it a second time', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    const thumb = await screen.findByRole('button', { name: /enlarge/i });
    expect(incidentReportsAPI.evidencePhotoBlob).toHaveBeenCalledTimes(1);

    fireEvent.click(thumb);

    // The overlay shows the SAME object URL the thumbnail already holds.
    const dialog = await screen.findByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(incidentReportsAPI.evidencePhotoBlob).toHaveBeenCalledTimes(1);
  });

  test('closes on Escape', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  test('a photo that failed to load is not clickable', async () => {
    incidentReportsAPI.evidencePhotoBlob.mockRejectedValue(new Error('403'));

    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /enlarge/i })).not.toBeInTheDocument();
  });
});
