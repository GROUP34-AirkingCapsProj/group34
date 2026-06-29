// Mock SweetAlert2. The jest.mock factory is hoisted above imports, so we
// define the spies inside it and expose them on the mock default export.
jest.mock('sweetalert2', () => {
  const mockFire = jest.fn();
  const mockShowValidationMessage = jest.fn();
  return {
    __esModule: true,
    default: {
      mixin: jest.fn(() => ({ fire: mockFire })),
      showValidationMessage: mockShowValidationMessage,
      __mockFire: mockFire,
      __mockShowValidationMessage: mockShowValidationMessage,
    },
  };
});

import Swal from 'sweetalert2';
import { confirmAction, confirmWithReason } from './confirm';

const mockFire = Swal.__mockFire;
const mockShowValidationMessage = Swal.__mockShowValidationMessage;

beforeEach(() => {
  mockFire.mockReset();
  mockShowValidationMessage.mockReset();
});

describe('confirmAction', () => {
  test('resolves true when confirmed', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true });
    await expect(confirmAction({ title: 'Approve?' })).resolves.toBe(true);
  });

  test('resolves false when dismissed/cancelled', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false, dismiss: 'cancel' });
    await expect(confirmAction({ title: 'Approve?' })).resolves.toBe(false);
  });

  test('primary tone uses question icon + primary button class', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true });
    await confirmAction({ title: 'Approve?', confirmText: 'Approve', tone: 'primary' });
    const opts = mockFire.mock.calls[0][0];
    expect(opts.icon).toBe('question');
    expect(opts.confirmButtonText).toBe('Approve');
    expect(opts.customClass.confirmButton).toContain('swal-confirm-primary');
  });

  test('danger tone (default) uses warning icon + danger button class', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true });
    await confirmAction({ title: 'Delete?' });
    const opts = mockFire.mock.calls[0][0];
    expect(opts.icon).toBe('warning');
    expect(opts.customClass.confirmButton).toContain('swal-confirm-danger');
  });
});

describe('confirmWithReason', () => {
  test('returns confirmed + trimmed reason on confirm', async () => {
    mockFire.mockResolvedValue({ isConfirmed: true, value: '  too expensive  ' });
    await expect(
      confirmWithReason({ title: 'Reject?' })
    ).resolves.toEqual({ confirmed: true, reason: 'too expensive' });
  });

  test('returns not-confirmed + empty reason on dismiss', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false, dismiss: 'cancel' });
    await expect(
      confirmWithReason({ title: 'Reject?' })
    ).resolves.toEqual({ confirmed: false, reason: '' });
  });

  test('renders a textarea input', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false });
    await confirmWithReason({ title: 'Reject?', reasonLabel: 'Why' });
    const opts = mockFire.mock.calls[0][0];
    expect(opts.input).toBe('textarea');
    expect(opts.inputLabel).toBe('Why');
  });

  test('preConfirm blocks empty reason when reasonRequired', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false });
    await confirmWithReason({ title: 'Reject?', reasonRequired: true, reasonLabel: 'Reason' });
    const { preConfirm } = mockFire.mock.calls[0][0];
    expect(preConfirm('   ')).toBe(false);
    expect(mockShowValidationMessage).toHaveBeenCalledWith('Reason is required');
  });

  test('preConfirm accepts a non-empty reason when reasonRequired', async () => {
    mockFire.mockResolvedValue({ isConfirmed: false });
    await confirmWithReason({ title: 'Reject?', reasonRequired: true });
    const { preConfirm } = mockFire.mock.calls[0][0];
    expect(preConfirm('a real reason')).toBe('a real reason');
    expect(mockShowValidationMessage).not.toHaveBeenCalled();
  });
});
