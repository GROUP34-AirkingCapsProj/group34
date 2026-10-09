import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import IncidentItemsRegisterPage from './IncidentItemsRegisterPage';
import { incidentItemsAPI, locationsAPI } from '../services/api';

jest.mock('../services/api', () => ({ incidentItemsAPI: { list: jest.fn(), completeRepair: jest.fn() }, locationsAPI: { getAll: jest.fn() } }));
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../context/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }));

test('requires completion notes and refreshes the register after returning a repaired item', async () => {
  locationsAPI.getAll.mockResolvedValue({ data: [] });
  incidentItemsAPI.list.mockResolvedValue({ data: [{ item_id: 3, incident_id: 7, product_id: 1,
    product_name: 'Aircon', quantity: 1, status: 'under_repair', outcome: 'repair' }] });
  incidentItemsAPI.completeRepair.mockResolvedValue({ success: true });
  render(<IncidentItemsRegisterPage />);
  fireEvent.click(await screen.findByRole('button', { name: 'Complete repair' }));
  expect(screen.getByRole('button', { name: 'Return to inventory' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Repair completion notes/), { target: { value: 'Motor replaced and tested' } });
  incidentItemsAPI.list.mockResolvedValue({ data: [] });
  fireEvent.click(screen.getByRole('button', { name: 'Return to inventory' }));
  await waitFor(() => expect(incidentItemsAPI.completeRepair).toHaveBeenCalledWith(7, 3, 'Motor replaced and tested'));
  expect(await screen.findByText('Repair completed. The item is now available in inventory.')).toBeInTheDocument();
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
});
