import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route, useLocation } from 'react-router-dom';
import LegacyAdminRedirect from './LegacyAdminRedirect';

// Renders wherever the redirect lands, so assertions can read the final URL.
function Landing() {
  const { pathname, search, hash } = useLocation();
  return <div data-testid="landed">{`${pathname}${search}${hash}`}</div>;
}

function renderAt(initialEntry) {
  render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route path="/admin/*" element={<LegacyAdminRedirect />} />
        <Route path="*" element={<Landing />} />
      </Routes>
    </MemoryRouter>
  );
  return screen.getByTestId('landed').textContent;
}

test('strips the /admin prefix from a simple path', () => {
  expect(renderAt('/admin/dashboard')).toBe('/dashboard');
});

test('strips the prefix from a nested path', () => {
  expect(renderAt('/admin/ist/42/receive')).toBe('/ist/42/receive');
});

test('preserves the query string', () => {
  expect(renderAt('/admin/incidents?tab=items')).toBe('/incidents?tab=items');
});

test('preserves both query string and hash', () => {
  expect(renderAt('/admin/transfers?tab=send&focus=transfer:7#row-7'))
    .toBe('/transfers?tab=send&focus=transfer:7#row-7');
});

test('sends bare /admin to the login page', () => {
  expect(renderAt('/admin')).toBe('/login');
});

test('sends a trailing-slash /admin/ to the login page', () => {
  expect(renderAt('/admin/')).toBe('/login');
});

test('collapses a double slash instead of producing a protocol-relative URL', () => {
  expect(renderAt('/admin//dashboard')).toBe('/dashboard');
});

test('collapses a long run of slashes', () => {
  expect(renderAt('/admin///ist///42')).toBe('/ist/42');
});

test('keeps an encoded hash inside a path segment out of the hash slot', () => {
  // A '#' that arrived percent-encoded is part of the path, not a fragment.
  expect(renderAt('/admin/report%23summary')).toBe('/report%23summary');
});

test('resolves a nested admin path without looping', () => {
  expect(renderAt('/admin/admin/x')).toBe('/x');
});
