import { render, screen, fireEvent } from '@testing-library/react';
import ErrorBoundary from './ErrorBoundary';

const Boom = ({ throws }) => {
  if (throws) throw new Error('isOver is not defined');
  return <p>page content</p>;
};

let consoleError;
beforeEach(() => { consoleError = jest.spyOn(console, 'error').mockImplementation(() => {}); });
afterEach(() => consoleError.mockRestore());

test('renders its children while nothing throws', () => {
  render(<ErrorBoundary><Boom throws={false} /></ErrorBoundary>);
  expect(screen.getByText('page content')).toBeInTheDocument();
});

test('shows a recoverable fallback instead of a blank screen when a child throws', () => {
  render(<ErrorBoundary><Boom throws /></ErrorBoundary>);
  expect(screen.getByRole('alert')).toBeInTheDocument();
  expect(screen.getByText(/something went wrong/i)).toBeInTheDocument();
  expect(screen.queryByText('page content')).not.toBeInTheDocument();
  // The message is surfaced so a user can quote it in a bug report.
  expect(screen.getByText(/isOver is not defined/)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /dashboard/i })).toBeInTheDocument();
});

test('logs the failure so it is not swallowed', () => {
  render(<ErrorBoundary><Boom throws /></ErrorBoundary>);
  expect(consoleError).toHaveBeenCalled();
  expect(consoleError.mock.calls.flat().some(arg => String(arg?.message ?? arg).includes('isOver is not defined')))
    .toBe(true);
});

test('try again re-renders the children', () => {
  const { rerender } = render(<ErrorBoundary><Boom throws /></ErrorBoundary>);
  expect(screen.getByRole('alert')).toBeInTheDocument();
  rerender(<ErrorBoundary><Boom throws={false} /></ErrorBoundary>);
  fireEvent.click(screen.getByRole('button', { name: /try again/i }));
  expect(screen.getByText('page content')).toBeInTheDocument();
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
