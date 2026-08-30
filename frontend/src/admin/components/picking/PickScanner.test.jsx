import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import PickScanner from './PickScanner';

// PickScanner is presentational: the camera is off by default, so the module is
// mocked only to keep the barcode-camera hook out of jsdom.
jest.mock('../CameraScanner', () => () => null);

const plan = [
  {
    product_id: 7,
    product_name: 'Split Type 1.5HP',
    needed: 2,
    suggestions: [
      { serial_number: null, serial_id: null, batch_id: 11, zone_label: 'A-01', received_at: '2026-01-02 09:00:00', quantity: 2 },
    ],
  },
];

const fifoVerdict = {
  scan_result: {
    status: 'FIFO_VIOLATION',
    accepted: false,
    overridable: true,
    message: 'A older batch must be picked first.',
    product_id: 7,
    product_name: 'Split Type 1.5HP',
    batch_id: 22,
    zone_label: 'C-09',
    expected_batch_id: 11,
    expected_zone_label: 'A-01',
    expected_received_at: '2026-01-02 09:00:00',
  },
};

/** Scan a barcode and wait for the FIFO verdict banner to land. */
async function scanIntoViolation() {
  await userEvent.type(screen.getByLabelText(/scan barcode/i), 'BC-999');
  await userEvent.click(screen.getByRole('button', { name: /scan and pick/i }));
  await screen.findByText(/older batch must be picked first/i);
}

function renderScanner(props = {}) {
  const onScan = jest.fn().mockResolvedValue(fifoVerdict);
  const onIncident = jest.fn();
  const { rerender } = render(
    <PickScanner plan={plan} onScan={onScan} onIncident={onIncident} {...props} />
  );
  const rerenderWith = (next) => rerender(
    <PickScanner plan={plan} onScan={onScan} onIncident={onIncident} {...props} {...next} />
  );
  return { onScan, onIncident, rerenderWith };
}

/** The expected zone is what lets a picker go and comply — guard it everywhere. */
function expectExpectedZoneVisible() {
  const zone = screen.getByText(/expected zone/i);
  expect(zone).toBeInTheDocument();
  expect(zone.textContent).toMatch(/A-01/);
}

test('without the override permission the picker gets the incident report only', async () => {
  renderScanner({ canOverrideFifo: false });
  await scanIntoViolation();

  expect(screen.getByRole('button', { name: /file incident report/i })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /override/i })).not.toBeInTheDocument();
  expectExpectedZoneVisible();
});

test('with the override permission the override control appears beside the incident report', async () => {
  renderScanner({ canOverrideFifo: true });
  await scanIntoViolation();

  expect(screen.getByRole('button', { name: /file incident report/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /^override/i })).toBeInTheDocument();
  // The reason field stays hidden until the picker asks for it.
  expect(screen.queryByLabelText(/reason for overriding fifo/i)).not.toBeInTheDocument();
  expectExpectedZoneVisible();
});

test('the override button reveals a reason field, and a short reason cannot be confirmed', async () => {
  renderScanner({ canOverrideFifo: true });
  await scanIntoViolation();

  await userEvent.click(screen.getByRole('button', { name: /^override/i }));

  const reason = screen.getByLabelText(/reason for overriding fifo/i);
  expect(reason).toBeInTheDocument();
  expect(screen.getByText(/recorded on the issuance line and in the audit trail/i)).toBeInTheDocument();

  // Nothing typed yet.
  expect(screen.getByRole('button', { name: /confirm override/i })).toBeDisabled();

  await userEvent.type(reason, '  short  ');
  expect(screen.getByRole('button', { name: /confirm override/i })).toBeDisabled();

  expectExpectedZoneVisible();
});

test('a valid reason re-issues the same scan through onScan with the override options', async () => {
  const { onScan } = renderScanner({ canOverrideFifo: true });
  await scanIntoViolation();
  expect(onScan).toHaveBeenCalledTimes(1);

  await userEvent.click(screen.getByRole('button', { name: /^override/i }));
  await userEvent.type(
    screen.getByLabelText(/reason for overriding fifo/i),
    'Older batch physically damaged  '
  );

  const confirm = screen.getByRole('button', { name: /confirm override/i });
  expect(confirm).toBeEnabled();
  await userEvent.click(confirm);

  await waitFor(() => expect(onScan).toHaveBeenCalledTimes(2));

  const [barcode, confirmedZone, quantity, opts] = onScan.mock.calls[1];
  expect(barcode).toBe('BC-999');           // the SAME barcode, not a re-type
  expect(confirmedZone).toBe('A-01');
  expect(quantity).toBe(2);
  expect(opts).toEqual({
    acceptFifoOverride: true,
    overrideReason: 'Older batch physically damaged',   // trimmed
  });

  expectExpectedZoneVisible();
});

test('a scan in flight disables the override control, so it cannot be double-submitted', async () => {
  const { rerenderWith } = renderScanner({ canOverrideFifo: true });
  await scanIntoViolation();
  await userEvent.click(screen.getByRole('button', { name: /^override/i }));
  await userEvent.type(
    screen.getByLabelText(/reason for overriding fifo/i),
    'Older batch physically damaged'
  );
  expect(screen.getByRole('button', { name: /confirm override/i })).toBeEnabled();

  rerenderWith({ busy: true });
  expect(screen.getByRole('button', { name: /confirm override/i })).toBeDisabled();
  expect(screen.getByLabelText(/reason for overriding fifo/i)).toBeDisabled();
  expectExpectedZoneVisible();
});
