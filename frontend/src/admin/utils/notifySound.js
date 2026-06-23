// Soft Messenger-style two-note pop for new notifications, via the WebAudio API.
//
// Mobile browsers (iOS Safari, Android Chrome) block audio that is not started
// from a user gesture, and an AudioContext starts "suspended" until resumed
// inside one. A sound triggered by a WebSocket event therefore stays silent on
// phones unless we (a) keep ONE shared context that was unlocked by an earlier
// tap, and (b) resume() it before playing. Best-effort throughout: any failure
// (autoplay policy, unsupported browser, phone on silent) is swallowed.

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
 * Play a brief ascending two-note "bu-dup" pop (C5 → G5). No-op if WebAudio is
 * unavailable; resumes a suspended context first so it works on mobile after the
 * page has been tapped once.
 */
export function playNotifySound() {
  const ctx = getCtx();
  if (!ctx) return;
  try {
    resumeIfNeeded(ctx);
    const t = ctx.currentTime;
    playNote(ctx, t,        523.25, 0.22, 0.75); // C5 — the "bu"
    playNote(ctx, t + 0.11, 783.99, 0.38, 0.9);  // G5 — the "dup" (louder, rings longer)
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
