import { playNotifySound } from './notifySound';

describe('playNotifySound', () => {
  test('never throws when WebAudio is unavailable', () => {
    const original = global.AudioContext;
    delete global.AudioContext;
    delete global.webkitAudioContext;
    expect(() => playNotifySound()).not.toThrow();
    global.AudioContext = original;
  });

  test('drives an oscillator when WebAudio is available', () => {
    const start = jest.fn();
    const stop = jest.fn();
    const connect = jest.fn();
    const ctx = {
      currentTime: 0,
      destination: {},
      createOscillator: () => ({ type: '', frequency: { value: 0 }, connect, start, stop }),
      createGain: () => ({ gain: { value: 0, setValueAtTime: jest.fn(), exponentialRampToValueAtTime: jest.fn() }, connect }),
      close: jest.fn(),
    };
    global.AudioContext = jest.fn(() => ctx);
    playNotifySound();
    expect(start).toHaveBeenCalled();
    expect(stop).toHaveBeenCalled();
  });
});
