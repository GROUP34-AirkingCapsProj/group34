import {
  playNotifySound,
  unlockNotifySound,
  _resetNotifySoundForTests,
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

  test('playNotifySound emits a two-note pop when WebAudio is available', () => {
    const { ctx, start, stop, createOscillator } = mockCtx('running');
    global.AudioContext = jest.fn(() => ctx);

    playNotifySound();

    expect(createOscillator).toHaveBeenCalledTimes(2); // "bu-dup"
    expect(start).toHaveBeenCalledTimes(2);
    expect(stop).toHaveBeenCalledTimes(2);
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
