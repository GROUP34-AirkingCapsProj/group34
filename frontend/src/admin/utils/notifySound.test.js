import { playNotifySound } from './notifySound';

describe('playNotifySound', () => {
  test('never throws when WebAudio is unavailable', () => {
    const original = global.AudioContext;
    delete global.AudioContext;
    delete global.webkitAudioContext;
    expect(() => playNotifySound()).not.toThrow();
    global.AudioContext = original;
  });

  test('plays a two-note pop when WebAudio is available', () => {
    const start = jest.fn();
    const stop = jest.fn();
    const connect = jest.fn();
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
        linearRampToValueAtTime: jest.fn(),
        exponentialRampToValueAtTime: jest.fn(),
      },
      connect,
    }));
    const ctx = { currentTime: 0, destination: {}, createOscillator, createGain, close: jest.fn() };
    global.AudioContext = jest.fn(() => ctx);

    playNotifySound();

    // Two notes → "bu-dup" pop (Messenger-style).
    expect(createOscillator).toHaveBeenCalledTimes(2);
    expect(start).toHaveBeenCalledTimes(2);
    expect(stop).toHaveBeenCalledTimes(2);
  });
});
