import React from 'react';
import { render, screen } from '@testing-library/react';
import ScanFeedback from './ScanFeedback';

test('replaces a successful scan with the red rejection and its reason', () => {
  const { rerender } = render(<ScanFeedback feedback={{ accepted: true, message: 'Added to delivery draft.', sequence: 1 }} />);
  expect(screen.getByText('✓ Scan recorded successfully').closest('.scan-feedback-result')).toHaveClass('scan-feedback-result--success');

  rerender(<ScanFeedback feedback={{ accepted: false, message: 'This barcode has already been received.', barcode: 'UNIT-123', sequence: 2 }} />);
  expect(screen.queryByText('✓ Scan recorded successfully')).not.toBeInTheDocument();
  expect(screen.getByText('✕ Scan error').closest('.scan-feedback-result')).toHaveClass('scan-feedback-result--error');
  expect(screen.getByText('This barcode has already been received.')).toBeInTheDocument();
  expect(screen.getByText('UNIT-123')).toBeInTheDocument();
});

test('shows a wrong barcode reason as an error', () => {
  render(<ScanFeedback feedback={{ accepted: false, message: 'This product is not on the selected Purchase Order.' }} />);
  expect(screen.getByText('This product is not on the selected Purchase Order.').closest('.scan-feedback-result')).toHaveClass('scan-feedback-result--error');
});

test('hides the previous result while checking another barcode', () => {
  render(<ScanFeedback busy feedback={{ accepted: true, message: 'Previous scan saved.' }} />);
  expect(screen.getByText(/Checking barcode/)).toBeInTheDocument();
  expect(screen.queryByText('Previous scan saved.')).not.toBeInTheDocument();
  expect(screen.queryByText('✓ Scan recorded successfully')).not.toBeInTheDocument();
});

test('identification without recording requires confirmation and is not green', () => {
  render(<ScanFeedback feedback={{ accepted: null, message: 'No stock change has been recorded.' }} />);
  expect(screen.getByText('Barcode identified — confirmation required').closest('.scan-feedback-result')).toHaveClass('scan-feedback-result--info');
  expect(screen.queryByText('✓ Scan recorded successfully')).not.toBeInTheDocument();
});
