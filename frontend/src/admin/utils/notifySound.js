// Notification audio supplied from the public assets directory.
// Browsers may block playback until the user interacts with the page, so the
// shared audio element is unlocked on the first tap/click/keypress.
export const NOTIFY_SOUND_URL = `${process.env.PUBLIC_URL || ''}/sound/utog.mp3`;

let notifyAudio = null;
let unlockBound = false;

function getAudio() {
  if (notifyAudio) return notifyAudio;
  if (typeof Audio === 'undefined') return null;

  try {
    notifyAudio = new Audio(NOTIFY_SOUND_URL);
    notifyAudio.preload = 'auto';
  } catch (_) {
    notifyAudio = null;
  }
  return notifyAudio;
}

/**
 * Unlock the audio element during a real user gesture so later notifications
 * triggered by the realtime connection can play on mobile browsers.
 */
export function unlockNotifySound() {
  const audio = getAudio();
  if (!audio) return;

  try {
    const previousVolume = audio.volume;
    audio.volume = 0;
    const result = audio.play();
    if (result && typeof result.then === 'function') {
      result.then(() => {
        audio.pause();
        audio.currentTime = 0;
        audio.volume = previousVolume;
      }).catch(() => {
        audio.volume = previousVolume;
      });
    } else {
      audio.pause();
      audio.currentTime = 0;
      audio.volume = previousVolume;
    }
  } catch (_) {
    // Sound is non-essential and may still be blocked by browser policy.
  }
}

export function initNotifySound() {
  if (unlockBound || typeof window === 'undefined' || !window.addEventListener) return;
  unlockBound = true;

  const events = ['touchstart', 'pointerdown', 'mousedown', 'keydown'];
  const handler = () => {
    unlockNotifySound();
    events.forEach((event) => window.removeEventListener(event, handler));
  };
  events.forEach((event) => window.addEventListener(event, handler, { passive: true }));
}

/** Play the configured MP3 once for each new notification burst. */
export function playNotifySound() {
  const audio = getAudio();
  if (!audio) return;

  try {
    audio.pause();
    audio.currentTime = 0;
    audio.volume = 1;
    const result = audio.play();
    if (result && typeof result.catch === 'function') result.catch(() => {});
  } catch (_) {
    // Sound is non-essential.
  }
}

/** Test-only: reset the singleton between test cases. */
export function _resetNotifySoundForTests() {
  try {
    if (notifyAudio) {
      notifyAudio.pause();
      notifyAudio.currentTime = 0;
    }
  } catch (_) {
    // ignore
  }
  notifyAudio = null;
  unlockBound = false;
}
