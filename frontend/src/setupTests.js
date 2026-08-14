// jest-dom adds custom jest matchers for asserting on DOM nodes.
// allows you to do things like:
// expect(element).toHaveTextContent(/react/i)
// learn more: https://github.com/testing-library/jest-dom
import '@testing-library/jest-dom';

// React Router v7 requires TextEncoder/TextDecoder in jsdom (CRA uses an older jsdom version).
const { TextEncoder, TextDecoder } = require('util');
global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;

/**
 * jsdom has no matchMedia, and SweetAlert2 — which every `toast.*` call goes
 * through — reaches for it.
 *
 * Stubbed globally because the failure is invisible where it matters most. A
 * component that toasts inside a try/catch throws the TypeError there, its own
 * catch swallows it, and everything AFTER the toast simply never runs. The test
 * then passes or fails for a reason that has nothing to do with what it asserts.
 * That cost an afternoon on IncidentInspectionPage; a per-file stub would only
 * mean the next person pays it again.
 */
if (typeof window !== 'undefined' && !window.matchMedia) {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  });
}
