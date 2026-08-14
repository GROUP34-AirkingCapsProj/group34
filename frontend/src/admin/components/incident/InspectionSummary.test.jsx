import React from 'react';
import { render, screen } from '@testing-library/react';
import {
  EvidenceSummary, FindingsSummary, OutcomeSummary, SignatureSummary,
} from './InspectionSummary';
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

describe('FindingsSummary', () => {
  test('shows the findings, the ASC fact, and who inspected it', () => {
    render(<FindingsSummary report={{
      inspection_findings: 'Compressor seized.\nCasing intact.',
      checked_by_asc: true,
      inspected_by_name: 'Rita Cruz',
      inspected_at: '2026-08-13T09:15:00+08:00',
    }} />);

    // Line breaks are preserved rather than collapsed — the inspector wrote two
    // separate observations, not one sentence.
    expect(screen.getByText(/Compressor seized\./)).toBeInTheDocument();
    expect(screen.getByText(/Checked by an authorised service centre/i)).toBeInTheDocument();
    // "on <date>" is asserted, not just the name: the timestamp is half of
    // "whose judgement, and when", and a matcher that stops at the name passes
    // just as happily when the date silently stops rendering. A NAMED month,
    // because 8/13/2026 and 13/08/2026 are the same string to two admins
    // cross-checking the same sheet.
    expect(screen.getByText(/Inspected by Rita Cruz on Aug /)).toBeInTheDocument();
  });

  test('drops an unparseable timestamp without dropping the inspector', () => {
    render(<FindingsSummary report={{
      inspection_findings: 'x',
      inspected_by_name: 'Rita Cruz',
      inspected_at: 'not-a-date',
    }} />);

    expect(screen.getByText('Inspected by Rita Cruz.')).toBeInTheDocument();
    expect(screen.queryByText(/ on /)).not.toBeInTheDocument();
  });

  test('states the ASC fact when it is false, rather than staying silent', () => {
    render(<FindingsSummary report={{ inspection_findings: 'x', checked_by_asc: false }} />);

    expect(screen.getByText(/Not checked by an authorised service centre/i)).toBeInTheDocument();
  });

  // The rule this whole change exists to enforce: an absence is stated, never
  // rendered as blank space.
  test('says so when no findings were written', () => {
    render(<FindingsSummary report={{ inspection_findings: null, checked_by_asc: false }} />);

    expect(screen.getByText(/recorded no written findings/i)).toBeInTheDocument();
  });

  test('says so when nobody has inspected it yet', () => {
    render(<FindingsSummary report={{ inspection_findings: null }} />);

    expect(screen.getByText(/No inspection has been submitted/i)).toBeInTheDocument();
  });
});

/** One quarantined unit the inspector has decided to scrap. */
const scrappedItem = (over = {}) => ({
  item_id: 11,
  product_id: 7,
  product_name: 'Split Type 1.5HP',
  status: 'quarantined',
  outcome: 'scrap',
  outcome_note: 'compressor seized, casing intact',
  quantity: 1,
  unit_role: 'indoor',
  serial_number: 'SN-1',
  batch_id: 3,
  warranty_state: 'expired',
  supplier_warranty: { state: 'expired', end_date: '2025-01-01', supplier_name: 'Supplier A' },
  ...over,
});

describe('OutcomeSummary', () => {
  test('shows what the inspector decided and why', () => {
    render(<OutcomeSummary items={[scrappedItem()]} />);

    expect(screen.getByText('Scrap')).toBeInTheDocument();
    expect(screen.getByText(/compressor seized, casing intact/)).toBeInTheDocument();
    expect(screen.getByText(/Split Type 1.5HP/)).toBeInTheDocument();
    expect(screen.getByText(/SN-1/)).toBeInTheDocument();
  });

  // The single most important line on the admin's screen: Confirm is the one
  // and only moment stock moves.
  test('warns when confirming will deduct the unit', () => {
    render(<OutcomeSummary items={[scrappedItem()]} />);

    expect(screen.getByText(/Confirming deducts this unit from stock/i)).toBeInTheDocument();
  });

  test('does not warn for an outcome that keeps the goods', () => {
    render(<OutcomeSummary items={[scrappedItem({ outcome: 'repair' })]} />);

    expect(screen.getByText('Send for repair')).toBeInTheDocument();
    expect(screen.queryByText(/deducts this unit from stock/i)).not.toBeInTheDocument();
  });

  test('both warranty clocks are shown, because they disagree by design', () => {
    render(<OutcomeSummary items={[scrappedItem({
      warranty_state: 'none',
      supplier_warranty: { state: 'in_warranty', end_date: '2029-08-12', supplier_name: 'Supplier A' },
    })]} />);

    expect(screen.getByText('Not sold yet')).toBeInTheDocument();
    expect(screen.getByText('Supplier warranty active')).toBeInTheDocument();
  });

  test('calls out a unit the inspector left undecided', () => {
    render(<OutcomeSummary items={[scrappedItem({ outcome: null, outcome_note: null })]} />);

    expect(screen.getByText(/No outcome recorded/i)).toBeInTheDocument();
  });

  test('an already-settled unit reads as history', () => {
    render(<OutcomeSummary items={[scrappedItem({ status: 'scrapped' })]} />);

    expect(screen.getByText(/Settled as/i)).toBeInTheDocument();
    // A settled unit was deducted by an earlier confirmation. Telling the admin
    // that confirming will deduct it is a lie about a movement that already
    // happened.
    expect(screen.queryByText(/deducts this unit from stock/i)).not.toBeInTheDocument();
  });

  // release() dismisses a report by changing status and deliberately ignores
  // whatever outcome an inspector recorded, so "closed" and "no outcome" is a
  // real state. It must not read as a pending decision.
  test('a settled unit with no outcome reads as closed, not as pending', () => {
    render(<OutcomeSummary items={[scrappedItem({ status: 'available', outcome: null, outcome_note: null })]} />);

    expect(screen.getByText(/Settled with no outcome recorded/i)).toBeInTheDocument();
    expect(screen.queryByText(/left this unit undecided/i)).not.toBeInTheDocument();
  });

  // A split-system aircon files as an indoor unit AND an outdoor unit on one
  // report. Rendering only the first means the admin confirms a two-unit
  // deduction having seen one unit — the exact failure this file exists to stop.
  test('renders every unit on the report, not just the first', () => {
    render(<OutcomeSummary items={[
      scrappedItem({ item_id: 11, unit_role: 'indoor', serial_number: 'SN-IN' }),
      scrappedItem({ item_id: 12, unit_role: 'outdoor', serial_number: 'SN-OUT' }),
    ]} />);

    expect(screen.getByText(/SN-IN/)).toBeInTheDocument();
    expect(screen.getByText(/SN-OUT/)).toBeInTheDocument();
    expect(screen.getAllByText(/Confirming deducts this unit from stock/i)).toHaveLength(2);
  });

  test('says so when the report names no units', () => {
    render(<OutcomeSummary items={[]} />);

    expect(screen.getByText(/names no physical units/i)).toBeInTheDocument();
  });
});

describe('SignatureSummary', () => {
  test('shows both names and the date from the signed hardcopy', () => {
    render(<SignatureSummary report={{
      signed_by_representative: 'A. Reyes',
      signed_by_branch_manager: 'M. Santos',
      signed_at: '2026-08-13',
    }} />);

    expect(screen.getByText('A. Reyes')).toBeInTheDocument();
    expect(screen.getByText('M. Santos')).toBeInTheDocument();
    expect(screen.getByText('2026-08-13')).toBeInTheDocument();
  });

  // An unsigned form is a fact the admin needs, not an empty box to skim past.
  test('names each missing signature rather than leaving a gap', () => {
    render(<SignatureSummary report={{}} />);

    expect(screen.getAllByText(/Not recorded/i)).toHaveLength(3);
  });

  // A name of spaces is not a signature. Rendered as-is it is indistinguishable
  // from a signed line, which is the one thing this block must never do.
  test('a whitespace-only name is not a signature', () => {
    render(<SignatureSummary report={{
      signed_by_representative: '   ',
      signed_by_branch_manager: 'M. Santos',
      signed_at: '2026-08-13',
    }} />);

    expect(screen.getAllByText(/Not recorded/i)).toHaveLength(1);
  });
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

  // The one sanctioned exception to this file's state-every-absence rule.
  // Reports filed before the camera existed do not know how their photo
  // arrived, and "unknown" would be a claim the data cannot support.
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
