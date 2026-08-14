import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { EvidenceThumbnail } from './EvidencePhoto';
import Modal from '../Modal';
import { incidentReportsAPI } from '../../services/api';

/**
 * The evidence thumbnail and the lightbox it opens.
 *
 * These assert on CONTENT and on behaviour, never on CSS classes. The
 * decide-mode test in IncidentDocument.test.jsx passed for months against four
 * completely empty sections because it only checked that each section carried
 * the readonly class — which is exactly how an admin came to be asked to
 * confirm a stock movement they could not see.
 *
 * They live in their own file rather than beside the summary suites because
 * someone changing the lightbox will look here, not in InspectionSummary.
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

// Modal is also role="dialog", so inside it the lightbox must be named.
const findLightbox = () => screen.findByRole('dialog', { name: 'Picture of the defect' });
const queryLightbox = () => screen.queryByRole('dialog', { name: 'Picture of the defect' });

describe('EvidenceThumbnail', () => {
  test('opens the photo full-size without fetching it a second time', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    const thumb = await screen.findByRole('button', { name: /enlarge/i });
    expect(incidentReportsAPI.evidencePhotoBlob).toHaveBeenCalledTimes(1);

    fireEvent.click(thumb);

    // The overlay shows the SAME object URL the thumbnail already holds.
    const dialog = await findLightbox();
    expect(dialog).toBeInTheDocument();
    expect(within(dialog).getByRole('img')).toHaveAttribute('src', 'blob:fake-url');
    expect(incidentReportsAPI.evidencePhotoBlob).toHaveBeenCalledTimes(1);
    // createObjectURL is mocked to a constant, so the src alone cannot tell a
    // reused URL from a freshly minted second one. Count the mints.
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
  });

  test('closes on Escape', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));
    expect(await findLightbox()).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    await waitFor(() => expect(queryLightbox()).not.toBeInTheDocument());
  });

  test('Escape inside the resolve drawer closes the photo, not the drawer', async () => {
    // The only place this feature runs is the admin's decide view, which lives
    // inside Modal — and Modal has its own document-level Escape handler that
    // unmounts the whole drawer. An admin pressing Escape to put a photo down
    // must not be thrown back to the incident list.
    const onClose = jest.fn();
    render(
      <Modal isOpen onClose={onClose} title="Incident report">
        <EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />
      </Modal>
    );

    fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));
    expect(await findLightbox()).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    await waitFor(() => expect(queryLightbox()).not.toBeInTheDocument());
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog', { name: 'Incident report' })).toBeInTheDocument();
  });

  test('closes when the backdrop is clicked, but not the photo itself', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));
    const dialog = await findLightbox();

    // Clicking the thing you came to look at must not dismiss it.
    fireEvent.click(within(dialog).getByRole('img'));
    expect(queryLightbox()).toBeInTheDocument();

    fireEvent.click(dialog);
    await waitFor(() => expect(queryLightbox()).not.toBeInTheDocument());
  });

  test('closes on the Close button', async () => {
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    fireEvent.click(await screen.findByRole('button', { name: /enlarge/i }));
    const dialog = await findLightbox();

    fireEvent.click(within(dialog).getByRole('button', { name: /close/i }));

    await waitFor(() => expect(queryLightbox()).not.toBeInTheDocument());
  });

  test('is a plain image, not a button, unless asked to be enlargeable', async () => {
    // The inspector's editable checklist renders these too. Making the button
    // unconditional would turn every thumbnail there into a control.
    render(<EvidenceThumbnail incidentId={5} evidenceId={9} alt="Picture of the defect" />);

    expect(await screen.findByAltText('Picture of the defect')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  test('a photo that failed to load is not clickable', async () => {
    incidentReportsAPI.evidencePhotoBlob.mockRejectedValue(new Error('403'));

    render(<EvidenceThumbnail incidentId={5} evidenceId={9} enlargeable alt="Picture of the defect" />);

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /enlarge/i })).not.toBeInTheDocument();
  });
});
