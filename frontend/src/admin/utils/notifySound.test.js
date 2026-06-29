import {
  playNotifySound,
  unlockNotifySound,
  _resetNotifySoundForTests,
  NOTIFY_MELODY,
  NOTIFY_REPEATS,
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

  test('playNotifySound plays the melody NOTIFY_REPEATS times when WebAudio is available', () => {
    const { ctx, start, stop, createOscillator } = mockCtx('running');
    global.AudioContext = jest.fn(() => ctx);

    playNotifySound();

    const expected = NOTIFY_MELODY.length * NOTIFY_REPEATS;
    expect(createOscillator).toHaveBeenCalledTimes(expected);
    expect(start).toHaveBeenCalledTimes(expected);
    expect(stop).toHaveBeenCalledTimes(expected);
  });

  test('the chime repeats at least twice so it is longer', () => {
    expect(NOTIFY_REPEATS).toBeGreaterThanOrEqual(2);
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
