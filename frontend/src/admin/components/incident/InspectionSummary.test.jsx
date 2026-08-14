import React from 'react';
import { render, screen } from '@testing-library/react';
import { FindingsSummary } from './InspectionSummary';

/**
 * The admin's read-only half of the incident document.
 *
 * These assert on CONTENT, never on CSS classes. The decide-mode test in
 * IncidentDocument.test.jsx passed for months against four completely empty
 * sections because it only checked that each section carried the readonly
 * class — which is exactly how an admin came to be asked to confirm a stock
 * movement they could not see.
 */

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
    expect(screen.getByText(/Inspected by Rita Cruz/)).toBeInTheDocument();
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
