// Short, asset-free notification chime via the WebAudio API. Best-effort:
// any failure (autoplay policy, unsupported browser) is swallowed silently.

/** Play a brief two-tone "ding". No-op if WebAudio is unavailable or blocked. */
export function playNotifySound() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const now = ctx.currentTime;

    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = 880; // A5
    gain.gain.value = 0.0001;
    osc.connect(gain);
    gain.connect(ctx.destination);

    // Quick attack, short exponential decay so it reads as a soft chime.
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.2, now + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.35);

    osc.start(now);
    osc.stop(now + 0.36);

    // Free the context shortly after the sound finishes.
    if (typeof ctx.close === 'function') {
      setTimeout(() => { try { ctx.close(); } catch (_) {} }, 600);
    }
  } catch (_) {
    // ignore — sound is non-essential
  }
}
