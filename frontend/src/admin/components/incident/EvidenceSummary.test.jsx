import React from 'react';
import { render, screen } from '@testing-library/react';
import { EvidenceSummary } from './EvidenceSummary';
import { incidentReportsAPI } from '../../services/api';

/**
 * The evidence checklist as the admin reads it.
 *
 * These assert on CONTENT, never on CSS classes. The decide-mode test in
 * IncidentDocument.test.jsx passed for months against four completely empty
 * sections because it only checked that each section carried the readonly
 * class — which is exactly how an admin came to be asked to confirm a stock
 * movement they could not see.
 */

// EvidenceSummary renders EvidenceThumbnail, which fetches each photo from the
// private disk. Nothing here tests the fetch — EvidencePhoto.test.jsx does —
// but without a stub the module reaches for a real axios client.
jest.mock('../../services/api', () => ({
  incidentReportsAPI: {
    evidencePhotoBlob: jest.fn(),
  },
}));

// jsdom has no object-URL plumbing. CRA's Jest resets mocks between tests, so
// the resolved value is armed here rather than in the factory.
beforeEach(() => {
  global.URL.createObjectURL = jest.fn(() => 'blob:fake-url');
  global.URL.revokeObjectURL = jest.fn();
  incidentReportsAPI.evidencePhotoBlob.mockResolvedValue(new Blob(['x']));
});

describe('EvidenceSummary', () => {
  // A `defective` report asks for four requirements — see
  // evidenceRequirementsFor() in utils/incidentDocument.
  const defectiveEvidence = [
    { evidence_id: 1, requirement: 'defect_photo', photo_path: 'incidents/a.jpg', photo_source: 'captured' },
    { evidence_id: 2, requirement: 'defect_photo', photo_path: 'incidents/b.jpg', photo_source: 'uploaded' },
    { evidence_id: 3, requirement: 'model_serial_sticker', photo_path: 'incidents/c.jpg', photo_source: 'captured' },
    { evidence_id: 4, requirement: 'box_two_sides', photo_path: null, skipped_reason: 'the box was already discarded' },
  ];

  test('shows every requirement with its photos, skips and outstanding items', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="defective"
      checkedByAsc={false}
      returnReasonCode={null}
      evidence={defectiveEvidence}
    />);

    expect(screen.getByText('2 attached')).toBeInTheDocument();
    expect(screen.getByText('Skipped')).toBeInTheDocument();
    // A skip SATISFIES a requirement — the reason is the evidence.
    expect(screen.getByText(/the box was already discarded/)).toBeInTheDocument();
    // accessories_warranty was never answered.
    expect(screen.getByText('Outstanding')).toBeInTheDocument();

    // Three photos, three enlargeable thumbnails.
    expect(await screen.findAllByRole('button', { name: /enlarge/i })).toHaveLength(3);
  });

  // Two photographs of one requirement are numbered, so a screen-reader user
  // can tell which one they have already opened.
  test('multiple photos of one requirement are told apart by name', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="defective"
      checkedByAsc={false}
      evidence={defectiveEvidence}
    />);

    expect(await screen.findByRole('button', { name: /Picture of the defect \(1 of 2\)/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Picture of the defect \(2 of 2\)/i })).toBeInTheDocument();
    // A lone photo is not numbered — there is nothing to distinguish it from.
    expect(screen.getByRole('button', { name: /^Model \/ serial sticker — click to enlarge$/i })).toBeInTheDocument();
  });

  // Provenance, and it matters more to the approver than to the photographer.
  // "Taken in-app" means the app watched the capture happen; "Uploaded from
  // device" means the file came from somewhere it cannot vouch for. An admin
  // writing off stock on the strength of a photograph should know which.
  //
  // Counted per label rather than merely asserted present: the fixture holds
  // two captures and one upload, so a badge hardcoded to either wording still
  // renders something for every photo and would pass a presence check.
  test('each photo says whether the app watched it being taken', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="defective"
      checkedByAsc={false}
      evidence={defectiveEvidence}
    />);

    await screen.findAllByRole('button', { name: /enlarge/i });

    expect(screen.getAllByText('Taken in-app')).toHaveLength(2);
    expect(screen.getAllByText('Uploaded from device')).toHaveLength(1);
  });

  // The one sanctioned exception to the state-every-absence rule. Reports filed
  // before the camera existed do not know how their photo arrived, and
  // "unknown" would be a claim the data cannot support.
  test('a photo of unknown provenance claims no provenance at all', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="wrong_type"
      checkedByAsc={false}
      evidence={[{ evidence_id: 7, requirement: 'model_serial_sticker', photo_path: 'incidents/old.jpg' }]}
    />);

    expect(await screen.findByRole('button', { name: /enlarge/i })).toBeInTheDocument();
    expect(screen.queryByText('Taken in-app')).not.toBeInTheDocument();
    expect(screen.queryByText('Uploaded from device')).not.toBeInTheDocument();
    expect(screen.queryByText(/unknown/i)).not.toBeInTheDocument();
  });

  // IncidentInspectionController enforces photo-xor-skip PER ROW, not per
  // requirement, so this shape is reachable: the inspector photographed one
  // side of the box and wrote off the other. Both halves must survive — a
  // badge counting rows would claim two photographs over one, and suppressing
  // the reason because a photo exists would silently drop the only account of
  // where the second side went.
  test('a requirement can hold a photo and a skip at once, and shows both', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="defective"
      checkedByAsc={false}
      evidence={[
        { evidence_id: 20, requirement: 'box_two_sides', photo_path: 'incidents/side1.jpg', photo_source: 'captured' },
        { evidence_id: 21, requirement: 'box_two_sides', photo_path: null, skipped_reason: 'the other side was destroyed in transit' },
      ]}
    />);

    expect(await screen.findByRole('button', { name: /enlarge/i })).toBeInTheDocument();
    // One photograph, not two rows.
    expect(screen.getByText('1 attached')).toBeInTheDocument();
    expect(screen.queryByText('2 attached')).not.toBeInTheDocument();
    // A photo on the requirement must not swallow the written reason.
    expect(screen.getByText(/the other side was destroyed in transit/)).toBeInTheDocument();
    // Photographed beats skipped for the badge: something IS attached here.
    expect(screen.queryByText('Skipped')).not.toBeInTheDocument();
  });

  test('an outstanding requirement says nothing was attached and no reason given', () => {
    render(<EvidenceSummary incidentId={5} reason="wrong_type" checkedByAsc={false} evidence={[]} />);

    expect(screen.getByText(/no reason was given/i)).toBeInTheDocument();
  });

  // Hiding it would read as a deleted file when it is still on the report.
  test('evidence against a requirement no longer asked for is still shown', () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="wrong_type"
      checkedByAsc={false}
      evidence={[{ evidence_id: 9, requirement: 'asc_service_report', photo_path: null, skipped_reason: 'no ASC nearby' }]}
    />);

    expect(screen.getByText(/Also attached: ASC service report/i)).toBeInTheDocument();
  });

  // Every orphan, not just the first.
  test('all orphaned evidence is shown, not merely the first row', () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="wrong_type"
      checkedByAsc={false}
      evidence={[
        { evidence_id: 9, requirement: 'asc_service_report', photo_path: null, skipped_reason: 'no ASC nearby' },
        { evidence_id: 10, requirement: 'receipt_or_dor', photo_path: null, skipped_reason: 'the receipt was never issued' },
      ]}
    />);

    expect(screen.getByText(/Also attached: ASC service report/i)).toBeInTheDocument();
    expect(screen.getByText(/Also attached: Receipt or delivery receipt/i)).toBeInTheDocument();
    expect(screen.getByText(/no ASC nearby/)).toBeInTheDocument();
    expect(screen.getByText(/the receipt was never issued/)).toBeInTheDocument();
  });

  // Orphans group by REQUIREMENT, exactly as the required list does. Two photos
  // of one requirement are one card badged "2 attached" — not two cards each
  // badged "1 attached", which would describe the same two photographs
  // differently depending only on whether the reason still asks for them.
  test('two orphans of one requirement are one card, counted together', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="wrong_type"
      checkedByAsc={false}
      evidence={[
        { evidence_id: 31, requirement: 'box_two_sides', photo_path: 'incidents/x.jpg', photo_source: 'captured' },
        { evidence_id: 32, requirement: 'box_two_sides', photo_path: 'incidents/y.jpg', photo_source: 'captured' },
      ]}
    />);

    expect(await screen.findAllByRole('button', { name: /enlarge/i })).toHaveLength(2);
    expect(screen.getAllByText(/Also attached: Two sides of the box/i)).toHaveLength(1);
    expect(screen.getByText('2 attached')).toBeInTheDocument();
    expect(screen.queryByText('1 attached')).not.toBeInTheDocument();
  });

  test('says so when the reason asks for no photographs', () => {
    render(<EvidenceSummary incidentId={5} reason="missing" checkedByAsc={false} evidence={[]} />);

    expect(screen.getByText(/asks for no photographs/i)).toBeInTheDocument();
  });

  // The two halves of that condition are separate facts. A `missing` report is
  // asked for nothing, but a photograph attached before the reason was changed
  // is still on it — announcing "no photographs" over the top of one is how the
  // admin comes to decide without seeing what is there.
  test('an attachment survives a reason that asks for no photographs', async () => {
    render(<EvidenceSummary
      incidentId={5}
      reason="missing"
      checkedByAsc={false}
      evidence={[{ evidence_id: 12, requirement: 'defect_photo', photo_path: 'incidents/d.jpg', photo_source: 'captured' }]}
    />);

    expect(screen.queryByText(/asks for no photographs/i)).not.toBeInTheDocument();
    expect(screen.getByText(/Also attached: Picture of the defect/i)).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: /enlarge/i })).toBeInTheDocument();
  });
});
