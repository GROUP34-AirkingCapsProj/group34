// Mock SweetAlert2.
// We cannot close over a variable in the jest.mock factory (hoisting issue),
// so we expose the fire spy via the mock module itself and retrieve it with
// jest.requireMock after import.
jest.mock('sweetalert2', () => {
  const mockFire = jest.fn();
  return {
    __esModule: true,
    default: {
      mixin: jest.fn(() => ({ fire: mockFire })),
      stopTimer: jest.fn(),
      resumeTimer: jest.fn(),
      // Expose the spy so tests can reach it via jest.requireMock().
      __mockFire: mockFire,
    },
  };
});

import Swal from 'sweetalert2';
import { toast } from './toast';

// Alias for readability in tests.
const mockFire = Swal.__mockFire;

beforeEach(() => mockFire.mockClear());

describe('toast util', () => {
  test('success with subtitle passes title, escaped html subtitle, and success accent class', () => {
    toast.success('Purchase Request sent', 'PR-2026-0042 — waiting for admin approval');
    expect(mockFire).toHaveBeenCalledTimes(1);
    const arg = mockFire.mock.calls[0][0];
    expect(arg.icon).toBe('success');
    expect(arg.title).toBe('Purchase Request sent');
    expect(arg.html).toContain('PR-2026-0042 — waiting for admin approval');
    expect(arg.html).toContain('swal-toast-subtitle');
    expect(arg.customClass.popup).toBe('swal-toast swal-toast-success');
  });

  test('success without subtitle omits html (backward compatible)', () => {
    toast.success('Saved');
    const arg = mockFire.mock.calls[0][0];
    expect(arg.title).toBe('Saved');
    expect(arg.html).toBeUndefined();
    expect(arg.customClass.popup).toBe('swal-toast swal-toast-success');
  });

  test('error escapes HTML in the subtitle to prevent injection', () => {
    toast.error("Couldn't send request", '<img src=x onerror=alert(1)>');
    const arg = mockFire.mock.calls[0][0];
    expect(arg.icon).toBe('error');
    expect(arg.html).not.toContain('<img');
    expect(arg.html).toContain('&lt;img');
  });

  test('error uses a longer timer than success', () => {
    toast.error('Save failed', 'reason');
    expect(mockFire.mock.calls[0][0].timer).toBe(2500);
  });
});
