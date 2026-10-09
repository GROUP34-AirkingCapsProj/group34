import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ReportsAnalytics from './ReportsAnalytics';
import { useAuth } from '../context/AuthContext';

jest.mock('../context/AuthContext', () => ({ useAuth: jest.fn() }));
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('./AnalyticsDashboard', () => ({ embedded }) => <div>Analytics content {embedded ? 'embedded' : 'standalone'}</div>);
jest.mock('./SystemReports', () => ({ embedded, children }) => <div>Reports content {embedded ? 'embedded' : 'standalone'}{children}</div>);

const renderAt = (permissions, path = '/admin/reports') => {
  useAuth.mockReturnValue({ can: feature => permissions.includes(feature) });
  render(<MemoryRouter initialEntries={[path]}><ReportsAnalytics /></MemoryRouter>);
};

test('shows charts and reports together without a reports/analytics tab switch', async () => {
  renderAt(['analytics_dashboard', 'report_viewing']);
  expect(await screen.findByText('Analytics content embedded')).toBeInTheDocument();
  expect(await screen.findByText('Reports content embedded')).toBeInTheDocument();
  expect(screen.getByText('Analytics content embedded')).toBeInTheDocument();
  expect(screen.queryByRole('tablist', { name: 'Reports and analytics' })).not.toBeInTheDocument();
});

test('analytics-only users can use reports hub without fetching report content', async () => {
  renderAt(['analytics_dashboard'], '/admin/reports?tab=reports');
  expect(await screen.findByText('Analytics content embedded')).toBeInTheDocument();
  expect(screen.queryByText('Reports content embedded')).not.toBeInTheDocument();
});

test('report-only users cannot enter analytics through a query parameter', async () => {
  renderAt(['report_viewing'], '/admin/reports?tab=analytics');
  expect(await screen.findByText('Reports content embedded')).toBeInTheDocument();
  expect(screen.queryByText('Analytics content embedded')).not.toBeInTheDocument();
});
