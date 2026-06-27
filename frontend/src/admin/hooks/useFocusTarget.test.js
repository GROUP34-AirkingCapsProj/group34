// frontend/src/admin/hooks/useFocusTarget.test.js
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { useFocusTarget } from './useFocusTarget';

function Probe() {
  const focusId = useFocusTarget('po');
  const loc = useLocation();
  return (
    <div>
      <span data-testid="focus">{focusId ?? 'none'}</span>
      <span data-testid="search">{loc.search || 'empty'}</span>
    </div>
  );
}

describe('useFocusTarget', () => {
  it('returns the id for a matching type and clears the param', async () => {
    render(
      <MemoryRouter initialEntries={['/admin/draft-po-creator?focus=po:42']}>
        <Probe />
      </MemoryRouter>
    );
    expect(screen.getByTestId('focus').textContent).toBe('42');
    await waitFor(() =>
      expect(screen.getByTestId('search').textContent).toBe('empty')
    );
  });

  it('returns none for a mismatched type', () => {
    render(
      <MemoryRouter initialEntries={['/admin/draft-po-creator?focus=pr:42']}>
        <Probe />
      </MemoryRouter>
    );
    expect(screen.getByTestId('focus').textContent).toBe('none');
  });
});
