import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
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


test('accepted scans keep feedback visible when the final product is completed', async () => {
  const onScan = jest.fn().mockResolvedValue({ scan_result: { status: 'ACCEPTED', accepted: true } });
  const { rerender } = render(<PickScanner plan={plan} onScan={onScan} />);
  expect(screen.getByText(/Next scan:/)).toHaveTextContent('2 pieces');
  fireEvent.change(screen.getByLabelText(/scan barcode/i), { target: { value: 'GOOD-1' } });
  await act(async () => { fireEvent.submit(screen.getByLabelText(/scan barcode/i).closest('form')); });
  expect(screen.getByText(/Scan recorded successfully/)).toBeInTheDocument();
  expect(screen.getByText('GOOD-1')).toBeInTheDocument();
  expect(screen.getByLabelText(/scan barcode/i)).toHaveFocus();
  rerender(<PickScanner plan={[]} onScan={onScan} />);
  expect(screen.getByText('All items picked')).toBeInTheDocument();
  expect(screen.getByText(/Scan recorded successfully/)).toBeInTheDocument();
});

test('rejects scans visibly and permits an immediate retry without dismissing feedback', async () => {
  const onScan = jest.fn().mockResolvedValueOnce({ scan_result: { status: 'ERROR', message: 'Wrong product' } })
    .mockResolvedValueOnce({ scan_result: { status: 'ACCEPTED', accepted: true } });
  render(<PickScanner plan={plan} onScan={onScan} />);
  const input = screen.getByLabelText(/scan barcode/i);
  fireEvent.change(input, { target: { value: 'WRONG' } });
  await act(async () => { fireEvent.submit(input.closest('form')); });
  expect(screen.getByText(/Scan error/)).toBeInTheDocument();
  expect(screen.getByText('Wrong product')).toBeInTheDocument();
  fireEvent.change(input, { target: { value: 'RIGHT' } });
  await act(async () => { fireEvent.submit(input.closest('form')); });
  expect(screen.getByText(/Scan recorded successfully/)).toBeInTheDocument();
  expect(onScan).toHaveBeenCalledTimes(2);
});

test('prevents overlapping submissions before the parent busy state updates', async () => {
  let resolveScan;
  const onScan = jest.fn(() => new Promise((resolve) => { resolveScan = resolve; }));
  render(<PickScanner plan={plan} onScan={onScan} />);
  const input = screen.getByLabelText(/scan barcode/i);
  fireEvent.change(input, { target: { value: 'FIRST' } });
  fireEvent.submit(input.closest('form'));
  fireEvent.change(input, { target: { value: 'SECOND' } });
  fireEvent.submit(input.closest('form'));
  expect(onScan).toHaveBeenCalledTimes(1);
  await act(async () => { resolveScan({ scan_result: { status: 'ACCEPTED' } }); });
  expect(screen.getByText(/Scan recorded successfully/)).toBeInTheDocument();
});


test('scanner input refocusing preserves the page scroll position after a scan', async () => {
  const focus = jest.spyOn(HTMLInputElement.prototype, 'focus');
  const onScan = jest.fn().mockResolvedValue({ scan_result: { status: 'ACCEPTED' } });
  render(<PickScanner plan={plan} onScan={onScan} />);
  const input = screen.getByLabelText(/scan barcode/i);
  fireEvent.change(input, { target: { value: 'GOOD' } });
  await act(async () => { fireEvent.submit(input.closest('form')); });
  expect(focus).toHaveBeenCalledWith({ preventScroll: true });
  focus.mockRestore();
});


test.each([
  ['one batch', [{ batch_id: 11, zone_label: 'A-01', quantity: 10 }]],
  ['multiple batches', [{ batch_id: 11, zone_label: 'A-01', quantity: 3 }, { batch_id: 12, zone_label: 'B-01', quantity: 7 }]],
  ['legacy stock without batch suggestions', []],
])('issue-out picks all 10 rubber pieces in one scan with %s', async (_, suggestions) => {
  const onScan = jest.fn().mockResolvedValue({ scan_result: { status: 'ACCEPTED', accepted: true } });
  render(<PickScanner fullOrderScan plan={[{ product_id: 7, product_name: 'Rubber', needed: 10, suggestions }]} onScan={onScan} />);
  const input = screen.getByLabelText(/scan barcode/i);
  fireEvent.change(input, { target: { value: 'RUBBER' } });
  await act(async () => { fireEvent.submit(input.closest('form')); });
  expect(onScan).toHaveBeenCalledTimes(1);
  expect(onScan).toHaveBeenCalledWith('RUBBER', suggestions[0]?.zone_label ?? null, 10, null);
});

test('issue-out still picks one aircon per serial scan', async () => {
  const onScan = jest.fn().mockResolvedValue({ scan_result: { status: 'ACCEPTED', accepted: true } });
  render(<PickScanner fullOrderScan plan={[{ product_id: 7, product_name: 'Aircon', needed: 10,
    suggestions: [{ serial_id: 1, serial_number: 'AC-001', quantity: 1 }] }]} onScan={onScan} />);
  const input = screen.getByLabelText(/scan barcode/i);
  fireEvent.change(input, { target: { value: 'AC-001' } });
  await act(async () => { fireEvent.submit(input.closest('form')); });
  expect(onScan).toHaveBeenCalledWith('AC-001', null, 1, null);
});
