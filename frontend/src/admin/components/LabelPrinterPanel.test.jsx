import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import LabelPrinterPanel from './LabelPrinterPanel';
import { labelPrintersAPI } from '../services/api';

jest.mock('../services/api', () => ({
  labelPrintersAPI: { getAll: jest.fn(), update: jest.fn(), test: jest.fn() },
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() },
}));

const response = {
  data: {
    locations: [
      {
        location_id: 1, location_name: 'Warehouse A', location_type: 'warehouse',
        printer: { transport: 'tcp', host: '192.168.1.50', port: 9100, share: '', timeout: 5 },
        configured: true,
      },
      {
        location_id: 2, location_name: 'Warehouse B', location_type: 'warehouse',
        printer: { transport: '', host: '', port: 9100, share: '', timeout: 5 },
        configured: false,
      },
    ],
    label: { width_mm: 100, height_mm: 25, dpi: 203 },
  },
};

beforeEach(() => {
  jest.clearAllMocks();
  labelPrintersAPI.getAll.mockResolvedValue(response);
});

test('it lists every location, including the ones with no printer', async () => {
  render(<LabelPrinterPanel />);

  expect(await screen.findByText('Warehouse A')).toBeInTheDocument();
  expect(screen.getByText('Warehouse B')).toBeInTheDocument();
  expect(screen.getByText(/192\.168\.1\.50/)).toBeInTheDocument();
  expect(screen.getByText(/not set up/i)).toBeInTheDocument();
});

/* Whoever orders the labels reads this. It is not editable here on purpose —
   the sticker layout is built around these numbers. */
test('it states the label stock it expects', async () => {
  render(<LabelPrinterPanel />);

  expect(await screen.findByText(/100 × 25 mm/)).toBeInTheDocument();
});

test('saving a location sends only that location', async () => {
  labelPrintersAPI.update.mockResolvedValue({ data: { printer: {}, configured: true } });

  render(<LabelPrinterPanel />);
  fireEvent.click(await screen.findByRole('button', { name: /set up/i }));

  fireEvent.change(screen.getByLabelText(/connection/i), { target: { value: 'tcp' } });
  fireEvent.change(screen.getByLabelText(/address/i), { target: { value: '10.0.0.9' } });
  fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

  await waitFor(() => {
    expect(labelPrintersAPI.update).toHaveBeenCalledWith(2, expect.objectContaining({
      transport: 'tcp', host: '10.0.0.9',
    }));
  });
});

/* The message is the whole point of the button — it says what to go and
   check — so it is rendered, not toasted away. */
test('a failed test renders its message', async () => {
  labelPrintersAPI.test.mockResolvedValue({
    data: { ok: false, message: '192.168.1.50:9100 did not answer. Check the printer is switched on.' },
  });

  render(<LabelPrinterPanel />);
  fireEvent.click(await screen.findByRole('button', { name: /test/i }));

  expect(await screen.findByText(/did not answer/i)).toBeInTheDocument();
});

/* Only a configured location can be tested — offering the button for a
   location with no printer would be a control that always reports failure. */
test('a location with no printer is not offered a test button', async () => {
  render(<LabelPrinterPanel />);
  await screen.findByText('Warehouse B');

  expect(screen.getAllByRole('button', { name: /test/i })).toHaveLength(1);
});

/* The address field belongs to the network transport. A share path in an
   address box would be saved into the wrong field and fail validation. */
test('the fields follow the connection type', async () => {
  render(<LabelPrinterPanel />);
  fireEvent.click(await screen.findByRole('button', { name: /set up/i }));

  expect(screen.queryByLabelText(/address/i)).not.toBeInTheDocument();

  fireEvent.change(screen.getByLabelText(/connection/i), { target: { value: 'tcp' } });
  expect(screen.getByLabelText(/address/i)).toBeInTheDocument();

  fireEvent.change(screen.getByLabelText(/connection/i), { target: { value: 'share' } });
  expect(screen.queryByLabelText(/address/i)).not.toBeInTheDocument();
  expect(screen.getByLabelText(/share/i)).toBeInTheDocument();
});
