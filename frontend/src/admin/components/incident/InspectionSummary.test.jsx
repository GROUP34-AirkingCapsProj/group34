import React from 'react';
import { render, screen } from '@testing-library/react';
import { FindingsSummary, OutcomeSummary } from './InspectionSummary';

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
  });

  test('says so when the report names no units', () => {
    render(<OutcomeSummary items={[]} />);

    expect(screen.getByText(/names no physical units/i)).toBeInTheDocument();
  });
});
