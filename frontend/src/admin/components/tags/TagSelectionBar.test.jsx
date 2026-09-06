import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import TagSelectionBar from './TagSelectionBar';

const props = {
  count: 4,
  ineligibleCount: 0,
  printerReady: true,
  sending: false,
  onSelectAll: jest.fn(),
  onClear: jest.fn(),
  onSend: jest.fn(),
  onSavePdf: jest.fn(),
};

test('it renders nothing until something is selected', () => {
  const { container } = render(<TagSelectionBar {...props} count={0} />);

  expect(container.firstChild).toBeNull();
});

test('it counts the selection', () => {
  render(<TagSelectionBar {...props} />);

  expect(screen.getByText(/4 selected/i)).toBeInTheDocument();
});

/* Bulk printing refuses supplier and manufacturer codes. Saying so after the
   click means a clerk walks to the printer expecting 38 labels and finds 32. */
test('it warns before the click when part of the selection cannot be bulk printed', () => {
  render(<TagSelectionBar {...props} count={38} ineligibleCount={6} />);

  expect(screen.getByText(/6 are supplier or manufacturer codes/i)).toBeInTheDocument();
  expect(screen.getByText(/won't be included/i)).toBeInTheDocument();
});

test('it stays quiet when every selected tag is ours', () => {
  render(<TagSelectionBar {...props} count={38} ineligibleCount={0} />);

  expect(screen.queryByText(/won't be included/i)).not.toBeInTheDocument();
});

test('it sends and saves', () => {
  render(<TagSelectionBar {...props} />);

  fireEvent.click(screen.getByRole('button', { name: /^print tags$/i }));
  expect(props.onSend).toHaveBeenCalled();

  fireEvent.click(screen.getByRole('button', { name: /print as pdf/i }));
  expect(props.onSavePdf).toHaveBeenCalled();
});

/* No printer configured means the direct-send button would always error. */
test('it offers only the PDF when no printer is configured', () => {
  render(<TagSelectionBar {...props} printerReady={false} />);

  expect(screen.queryByRole('button', { name: /^print tags$/i })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: /print as pdf/i })).toBeInTheDocument();
});

/* Spec section 2: the PDF path never reaches the server, so it cannot be
   stamped. Left unsaid, the print state quietly under-reports and a clerk
   reprints labels they already have on a sheet. */
test('it says what the PDF route is, and that it does not count as sent', () => {
  render(<TagSelectionBar {...props} />);

  expect(screen.getByText(/cut out/i)).toBeInTheDocument();
  expect(screen.getByText(/not counted as sent/i)).toBeInTheDocument();
});
