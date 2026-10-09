import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import AnalyticsDashboard from './AnalyticsDashboard';
import { analyticsAPI } from '../services/api';

jest.mock('../services/api');
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('react-chartjs-2', () => ({ Bar: () => <div data-testid="bar-chart" /> }));

describe('AnalyticsDashboard', () => {
  beforeEach(() => {
    jest.clearAllMocks();

    analyticsAPI.getFilters = jest.fn().mockResolvedValue({
      data: {
        locations: [{ id: 1, name: 'CDO Warehouse A', type: 'warehouse' }],
        categories: [{ id: 4, name: 'Aircon' }],
      },
    });

    analyticsAPI.getStockLevel = jest.fn().mockResolvedValue({
      data: {
        statsData: {
          totalStockValue: 123456,
          totalUnits: 789,
          lowStockItems: 5,
          criticalItems: 2,
        },
        stockDistribution: {
          optimal:   { count: 10, percentage: 50 },
          lowStock:  { count: 5,  percentage: 25 },
          critical:  { count: 2,  percentage: 10 },
          overstock: { count: 3,  percentage: 15 },
        },
        reorderCompliance: { rate: 65, belowReorderPoint: 7, aboveSafetyStock: 13 },
        criticalItems: [],
        overstockItems: [],
      },
    });

    analyticsAPI.getMovement = jest.fn().mockResolvedValue({ data: {} });
    analyticsAPI.getPredictions = jest.fn().mockResolvedValue({ data: {} });
    analyticsAPI.getProfitLoss = jest.fn().mockResolvedValue({ data: {} });
    analyticsAPI.getLocationPerformance = jest.fn().mockResolvedValue({ data: {} });
  });

  it('renders server-computed values rather than computing locally', async () => {
    render(<AnalyticsDashboard />);

    await waitFor(() => {
      expect(screen.getByText('₱123,456')).toBeInTheDocument();
    });

    expect(screen.getByText('789')).toBeInTheDocument();
    expect(screen.getByText('65%')).toBeInTheDocument();
    expect(analyticsAPI.getStockLevel).toHaveBeenCalledTimes(1);
  });

  it('populates the location filter from the filters endpoint', async () => {
    render(<AnalyticsDashboard />);

    await waitFor(() => {
      expect(screen.getByText('CDO Warehouse A')).toBeInTheDocument();
    });

    expect(screen.getByText('Aircon')).toBeInTheDocument();
  });

  it('hides previous charts and records during a new filter request and on failure', async () => {
    render(<AnalyticsDashboard />);
    await screen.findByText('₱123,456');
    await screen.findByRole('option', { name: 'Aircon' });
    let rejectRequest;
    analyticsAPI.getStockLevel.mockImplementationOnce(() => new Promise((resolve, reject) => { rejectRequest = reject; }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Category' }), { target: { value: '4' } });
    expect(screen.queryByText('₱123,456')).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Stock records' })).not.toBeInTheDocument();
    const log = jest.spyOn(console, 'error').mockImplementation(() => {});
    rejectRequest(new Error('Filter request failed'));
    await screen.findByText('Error: Filter request failed');
    expect(screen.queryByText('₱123,456')).not.toBeInTheDocument();
    log.mockRestore();
  });
});
