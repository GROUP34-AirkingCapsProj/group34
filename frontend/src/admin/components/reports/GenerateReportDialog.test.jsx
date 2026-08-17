import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import GenerateReportDialog from './GenerateReportDialog';

/**
 * The dialog's whole reason to exist is that its inputs come from
 * `report.parameters` and nothing else — a report that declares no date
 * range must not show one, and a report that lists only csv must not offer a
 * PDF radio. Those are exactly the two ways a hardcoded dialog would drift
 * from what the backend actually validates.
 */

const locations = [
  { location_id: 1, location_name: 'Main Warehouse' },
  { location_id: 2, location_name: 'Cebu Branch' },
];

// No date range: an inventory snapshot report, filterable only by location.
const inventoryReport = {
  key: 'inventory_summary',
  title: 'Inventory Summary',
  description: 'Complete inventory status across all locations',
  formats: ['pdf', 'csv'],
  parameters: [
    { key: 'location_id', type: 'location', label: 'Location', required: false, options: {}, default: null },
  ],
};

// A required period: the transaction report.
const transactionReport = {
  key: 'transaction_report',
  title: 'Transaction Report',
  description: 'All inventory movements and transactions',
  formats: ['pdf', 'csv'],
  parameters: [
    { key: 'period', type: 'date_range', label: 'Period', required: true, options: {}, default: null },
  ],
};

// csv-only report — nothing here should ever offer a PDF option.
const csvOnlyReport = {
  key: 'low_stock',
  title: 'Low Stock Report',
  description: 'Items below reorder level',
  formats: ['csv'],
  parameters: [],
};

const noop = () => {};

describe('GenerateReportDialog', () => {
  test('a report declaring no date range shows no date inputs, but does show its declared location select', () => {
    render(
      <GenerateReportDialog report={inventoryReport} locations={locations} onGenerate={noop} onClose={noop} />
    );

    expect(screen.queryByLabelText(/from/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/to$/i)).not.toBeInTheDocument();

    const locationSelect = screen.getByLabelText('Location');
    expect(locationSelect).toBeInTheDocument();
    expect(screen.getByText('Main Warehouse')).toBeInTheDocument();
    expect(screen.getByText('All locations')).toBeInTheDocument();
  });

  test('a report declaring a required period shows both date inputs', () => {
    render(
      <GenerateReportDialog report={transactionReport} locations={locations} onGenerate={noop} onClose={noop} />
    );

    expect(screen.getByLabelText(/Period — From/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Period — To/i)).toBeInTheDocument();
  });

  test('submitting an empty required period does not call onGenerate and shows an error', () => {
    const onGenerate = jest.fn();
    render(
      <GenerateReportDialog report={transactionReport} locations={locations} onGenerate={onGenerate} onClose={noop} />
    );

    fireEvent.click(screen.getByRole('button', { name: /generate/i }));

    expect(onGenerate).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  test('a start date after the end date blocks submit', () => {
    const onGenerate = jest.fn();
    render(
      <GenerateReportDialog report={transactionReport} locations={locations} onGenerate={onGenerate} onClose={noop} />
    );

    fireEvent.change(screen.getByLabelText(/Period — From/i), { target: { value: '2026-08-20' } });
    fireEvent.change(screen.getByLabelText(/Period — To/i), { target: { value: '2026-08-10' } });
    fireEvent.click(screen.getByRole('button', { name: /generate/i }));

    expect(onGenerate).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent(/before/i);
  });

  test('submitting valid values calls onGenerate with the right shape', () => {
    const onGenerate = jest.fn();
    render(
      <GenerateReportDialog report={transactionReport} locations={locations} onGenerate={onGenerate} onClose={noop} />
    );

    fireEvent.change(screen.getByLabelText(/Period — From/i), { target: { value: '2026-08-01' } });
    fireEvent.change(screen.getByLabelText(/Period — To/i), { target: { value: '2026-08-15' } });
    fireEvent.click(screen.getByRole('radio', { name: /csv/i }));
    fireEvent.click(screen.getByRole('button', { name: /generate/i }));

    expect(onGenerate).toHaveBeenCalledTimes(1);
    expect(onGenerate).toHaveBeenCalledWith({
      reportKey: 'transaction_report',
      format: 'csv',
      parameters: { date_from: '2026-08-01', date_to: '2026-08-15' },
    });
  });

  test('an optional parameter left empty is omitted from the submitted parameters', () => {
    const onGenerate = jest.fn();
    render(
      <GenerateReportDialog report={inventoryReport} locations={locations} onGenerate={onGenerate} onClose={noop} />
    );

    fireEvent.click(screen.getByRole('button', { name: /generate/i }));

    expect(onGenerate).toHaveBeenCalledWith({
      reportKey: 'inventory_summary',
      format: 'pdf',
      parameters: {},
    });
  });

  test('only declared formats are offered — a csv-only report shows no PDF radio', () => {
    render(
      <GenerateReportDialog report={csvOnlyReport} locations={locations} onGenerate={noop} onClose={noop} />
    );

    expect(screen.queryByRole('radio', { name: /pdf/i })).not.toBeInTheDocument();
    expect(screen.getByRole('radio', { name: /csv/i })).toBeInTheDocument();
  });

  test('is an accessible dialog named after the report', () => {
    render(
      <GenerateReportDialog report={inventoryReport} locations={locations} onGenerate={noop} onClose={noop} />
    );

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAccessibleName(/Inventory Summary/i);
  });
});
