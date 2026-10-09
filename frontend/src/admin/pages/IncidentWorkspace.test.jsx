import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import IncidentWorkspace from './IncidentWorkspace';

jest.mock('../components/AdminLayout', () => ({ children }) => <div data-testid="layout">{children}</div>);
jest.mock('./IncidentReportsPage', () => ({ embedded }) => <p>Reports {embedded ? 'embedded' : 'standalone'}</p>);
jest.mock('./IncidentInspectionPage', () => ({ embedded }) => <p>Inspections {embedded ? 'embedded' : 'standalone'}</p>);
jest.mock('./IncidentItemsRegisterPage', () => ({ embedded }) => <p>Items {embedded ? 'embedded' : 'standalone'}</p>);

test('combines three incident tabs in one layout and supports keyboard switching', async () => {
  render(<MemoryRouter initialEntries={['/admin/incidents']}><IncidentWorkspace /></MemoryRouter>);
  expect(await screen.findByText('Reports embedded')).toBeInTheDocument();
  expect(screen.getAllByRole('tab')).toHaveLength(3);
  fireEvent.click(screen.getByRole('tab', { name: 'Inspections' }));
  expect(await screen.findByText('Inspections embedded')).toBeInTheDocument();
  expect(screen.queryByText('Reports embedded')).not.toBeInTheDocument();
  fireEvent.keyDown(screen.getByRole('tab', { name: 'Inspections' }), { key: 'ArrowRight' });
  expect(await screen.findByText('Items embedded')).toBeInTheDocument();
  expect(screen.getByRole('tab', { name: 'Incident Items' })).toHaveAttribute('aria-selected', 'true');
  expect(screen.getAllByTestId('layout')).toHaveLength(1);
});

test('opens the tab from the URL when revisiting a saved link', async () => {
  render(<MemoryRouter initialEntries={['/admin/incidents?tab=items']}><IncidentWorkspace /></MemoryRouter>);
  expect(await screen.findByText('Items embedded')).toBeInTheDocument();
});
