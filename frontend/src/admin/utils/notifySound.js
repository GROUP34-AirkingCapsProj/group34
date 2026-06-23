// Soft "pop" notification chime — a short Messenger-style two-note blip via the
// WebAudio API. Best-effort: any failure (autoplay policy, unsupported browser)
// is swallowed silently, since the sound is non-essential.

/** Schedule one short sine "note" with a quick attack and soft exponential decay. */
function playNote(ctx, startAt, freq, duration, peak) {
  const osc = ctx.createOscillator();
  const gain = ctx.createGain();
  osc.type = 'sine';
  osc.frequency.value = freq;
  osc.connect(gain);
  gain.connect(ctx.destination);

  const end = startAt + duration;
  gain.gain.setValueAtTime(0.0001, startAt);
  gain.gain.exponentialRampToValueAtTime(peak, startAt + 0.015); // snappy attack → "pop"
  gain.gain.exponentialRampToValueAtTime(0.0001, end);           // soft tail

  osc.start(startAt);
  osc.stop(end + 0.02);
}

/**
 * Play a brief ascending two-note "bu-dup" pop (C5 → G5), evocative of a
 * messaging-app notification. No-op if WebAudio is unavailable or blocked.
 */
export function playNotifySound() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const t = ctx.currentTime;

    playNote(ctx, t,        523.25, 0.12, 0.22); // C5 — the "bu"
    playNote(ctx, t + 0.09, 783.99, 0.16, 0.25); // G5 — the "dup"

    // Free the context shortly after the sound finishes.
    if (typeof ctx.close === 'function') {
      setTimeout(() => { try { ctx.close(); } catch (_) {} }, 700);
    }
  } catch (_) {
    // ignore — sound is non-essential
  }
}
