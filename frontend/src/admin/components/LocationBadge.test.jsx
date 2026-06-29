import { render, screen } from '@testing-library/react';
import LocationBadge from './LocationBadge';

test('scoped user sees their location name', () => {
  render(<LocationBadge isCentralized={false} locationName="Cebu Branch" />);
  expect(screen.getByText(/Cebu Branch/i)).toBeInTheDocument();
});

test('centralized user sees All Locations', () => {
  render(<LocationBadge isCentralized={true} locationName={null} />);
  expect(screen.getByText(/All Locations/i)).toBeInTheDocument();
});
