// Soft Messenger-style two-note pop for new notifications, via the WebAudio API.
//
// Mobile browsers (iOS Safari, Android Chrome) block audio that is not started
// from a user gesture, and an AudioContext starts "suspended" until resumed
// inside one. A sound triggered by a WebSocket event therefore stays silent on
// phones unless we (a) keep ONE shared context that was unlocked by an earlier
// tap, and (b) resume() it before playing. Best-effort throughout: any failure
// (autoplay policy, unsupported browser, phone on silent) is swallowed.

// Ascending bell arpeggio (C5–E5–G5–C6) that rises and rings out (~1.1s). Longer
// and more melodic than a single pop so users actually notice it. Each note:
// start offset (s), frequency (Hz), duration (s), peak gain.
export const NOTIFY_MELODY = [
  { at: 0.00, freq: 523.25,  dur: 0.22, peak: 0.70 }, // C5
  { at: 0.15, freq: 659.25,  dur: 0.22, peak: 0.78 }, // E5
  { at: 0.30, freq: 783.99,  dur: 0.26, peak: 0.85 }, // G5
  { at: 0.48, freq: 1046.50, dur: 0.60, peak: 0.95 }, // C6 — resolves & rings out
];

let audioCtx = null;
let unlockBound = false;

function getCtx() {
  if (audioCtx) return audioCtx;
  const Ctx =
    typeof window !== 'undefined' &&
    (window.AudioContext || window.webkitAudioContext);
  if (!Ctx) return null;
  try {
    audioCtx = new Ctx();
  } catch (_) {
    audioCtx = null;
  }
  return audioCtx;
}

/** Schedule one short sine "note" with a quick attack and soft exponential decay. */
function playNote(ctx, startAt, freq, duration, peak) {
  const osc = ctx.createOscillator();
  const gain = ctx.createGain();
  // Triangle reads louder/brighter than a pure sine on small phone speakers.
  osc.type = 'triangle';
  osc.frequency.value = freq;
  osc.connect(gain);
  gain.connect(ctx.destination);

  const end = startAt + duration;
  gain.gain.setValueAtTime(0.0001, startAt);
  gain.gain.exponentialRampToValueAtTime(peak, startAt + 0.006); // very fast attack → punch
  gain.gain.exponentialRampToValueAtTime(0.0001, end);           // longer ringing tail

  osc.start(startAt);
  osc.stop(end + 0.02);
}

/** Resume the shared context if a browser has parked it in "suspended". */
function resumeIfNeeded(ctx) {
  try {
    if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
      ctx.resume();
    }
  } catch (_) {
    // ignore
  }
}

/**
 * Unlock/resume the shared audio context. Call this from within a real user
 * gesture (tap/click/keypress) — required so later WebSocket-triggered sounds
 * can play on mobile. Safe to call repeatedly.
 */
export function unlockNotifySound() {
  const ctx = getCtx();
  if (!ctx) return;
  resumeIfNeeded(ctx);
}

/**
 * Install one-time listeners that unlock audio on the first user interaction.
 * Idempotent — installs the listeners only once per page.
 */
export function initNotifySound() {
  if (unlockBound || typeof window === 'undefined' || !window.addEventListener) return;
  unlockBound = true;
  const events = ['touchstart', 'pointerdown', 'mousedown', 'keydown'];
  const handler = () => {
    unlockNotifySound();
    events.forEach((evt) => window.removeEventListener(evt, handler));
  };
  events.forEach((evt) => window.addEventListener(evt, handler, { passive: true }));
}

/**
 * Play the ascending notification arpeggio (NOTIFY_MELODY). No-op if WebAudio is
 * unavailable; resumes a suspended context first so it works on mobile after the
 * page has been tapped once.
 */
export function playNotifySound() {
  const ctx = getCtx();
  if (!ctx) return;
  try {
    resumeIfNeeded(ctx);
    const t = ctx.currentTime;
    NOTIFY_MELODY.forEach((n) => playNote(ctx, t + n.at, n.freq, n.dur, n.peak));
  } catch (_) {
    // ignore — sound is non-essential
  }
}

/** Test-only: reset the module singleton between test cases. */
export function _resetNotifySoundForTests() {
  try {
    if (audioCtx && typeof audioCtx.close === 'function') audioCtx.close();
  } catch (_) {
    // ignore
  }
  audioCtx = null;
  unlockBound = false;
}
