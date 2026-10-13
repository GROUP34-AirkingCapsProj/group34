import { canAutoFocusScanInput, focusScanInput } from './scanInputFocus';

const originalMatchMedia = window.matchMedia;
afterEach(() => { window.matchMedia = originalMatchMedia; });

test('does not automatically focus barcode inputs in mobile views', () => {
  window.matchMedia = jest.fn(() => ({ matches: true }));
  const input = { focus: jest.fn() };
  focusScanInput(input);
  expect(input.focus).not.toHaveBeenCalled();
  expect(canAutoFocusScanInput()).toBe(false);
  expect(window.matchMedia).toHaveBeenCalledWith('(pointer: coarse), (max-width: 768px)');
});

test('keeps automatic focus for desktop barcode scanners', () => {
  window.matchMedia = jest.fn(() => ({ matches: false }));
  const input = { focus: jest.fn() };
  focusScanInput(input);
  expect(input.focus).toHaveBeenCalledWith({ preventScroll: true });
  expect(canAutoFocusScanInput()).toBe(true);
});

test('allows an input to be absent during unmount', () => {
  window.matchMedia = jest.fn(() => ({ matches: false }));
  expect(() => focusScanInput(null)).not.toThrow();
});
