import {
  playNotifySound,
  unlockNotifySound,
  _resetNotifySoundForTests,
  NOTIFY_MELODY,
} from './notifySound';

function mockCtx(state) {
  const start = jest.fn();
  const stop = jest.fn();
  const connect = jest.fn();
  const resume = jest.fn();
  const createOscillator = jest.fn(() => ({
    type: '',
    frequency: { value: 0, setValueAtTime: jest.fn() },
    connect,
    start,
    stop,
  }));
  const createGain = jest.fn(() => ({
    gain: {
      value: 0,
      setValueAtTime: jest.fn(),
      exponentialRampToValueAtTime: jest.fn(),
    },
    connect,
  }));
  const ctx = {
    state,
    currentTime: 0,
    destination: {},
    createOscillator,
    createGain,
    resume,
    close: jest.fn(),
  };
  return { ctx, start, stop, resume, createOscillator };
}

describe('notifySound', () => {
  afterEach(() => {
    _resetNotifySoundForTests();
    delete global.AudioContext;
    delete global.webkitAudioContext;
  });

  test('playNotifySound never throws when WebAudio is unavailable', () => {
    delete global.AudioContext;
    delete global.webkitAudioContext;
    expect(() => playNotifySound()).not.toThrow();
  });

  test('the melody is a multi-note tune, not a single pop', () => {
    expect(NOTIFY_MELODY.length).toBeGreaterThanOrEqual(3);
  });

  test('playNotifySound plays every note of the melody when WebAudio is available', () => {
    const { ctx, start, stop, createOscillator } = mockCtx('running');
    global.AudioContext = jest.fn(() => ctx);

    playNotifySound();

    expect(createOscillator).toHaveBeenCalledTimes(NOTIFY_MELODY.length);
    expect(start).toHaveBeenCalledTimes(NOTIFY_MELODY.length);
    expect(stop).toHaveBeenCalledTimes(NOTIFY_MELODY.length);
  });

  test('playNotifySound resumes a suspended context first (mobile autoplay)', () => {
    const { ctx, resume } = mockCtx('suspended');
    global.AudioContext = jest.fn(() => ctx);

    playNotifySound();

    expect(resume).toHaveBeenCalled();
  });

  test('repeated calls reuse the same AudioContext (no per-call context)', () => {
    const { ctx } = mockCtx('running');
    const Ctor = jest.fn(() => ctx);
    global.AudioContext = Ctor;

    playNotifySound();
    playNotifySound();

    expect(Ctor).toHaveBeenCalledTimes(1);
  });

  test('unlockNotifySound resumes the shared context (called from a gesture)', () => {
    const { ctx, resume } = mockCtx('suspended');
    global.AudioContext = jest.fn(() => ctx);

    unlockNotifySound();

    expect(resume).toHaveBeenCalled();
  });
});
