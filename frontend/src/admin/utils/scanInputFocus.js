// Automatic focus opens the on-screen keyboard on mobile. Keep it for desktop
// barcode scanners; mobile users can tap the field when they want to type.
export function canAutoFocusScanInput() {
  return typeof window !== 'undefined'
    && !window.matchMedia?.('(pointer: coarse), (max-width: 768px)').matches;
}

export function focusScanInput(input) {
  if (canAutoFocusScanInput()) input?.focus({ preventScroll: true });
}
