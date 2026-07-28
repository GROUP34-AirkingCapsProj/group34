import {
  playNotifySound,
  unlockNotifySound,
  _resetNotifySoundForTests,
  NOTIFY_SOUND_URL,
} from './notifySound';

describe('notifySound', () => {
  let audio;

  beforeEach(() => {
    audio = {
      preload: '',
      volume: 1,
      currentTime: 5,
      play: jest.fn(() => Promise.resolve()),
      pause: jest.fn(),
    };
    global.Audio = jest.fn(() => audio);
  });

  afterEach(() => {
    _resetNotifySoundForTests();
    delete global.Audio;
  });

  test('uses the supplied notification MP3', () => {
    expect(NOTIFY_SOUND_URL).toContain('/sound/alert_ringtone.mp3');
  });

  test('plays the supplied file from the beginning', () => {
    playNotifySound();

    expect(global.Audio).toHaveBeenCalledWith(NOTIFY_SOUND_URL);
    expect(audio.preload).toBe('auto');
    expect(audio.currentTime).toBe(0);
    expect(audio.volume).toBe(1);
    expect(audio.play).toHaveBeenCalledTimes(1);
  });

  test('repeated calls reuse the same audio element', () => {
    playNotifySound();
    playNotifySound();

    expect(global.Audio).toHaveBeenCalledTimes(1);
    expect(audio.play).toHaveBeenCalledTimes(2);
  });

  test('unlock attempts silent playback for mobile browser policies', async () => {
    unlockNotifySound();
    await Promise.resolve();

    expect(audio.play).toHaveBeenCalled();
    expect(audio.pause).toHaveBeenCalled();
    expect(audio.currentTime).toBe(0);
    expect(audio.volume).toBe(1);
  });

  test('never throws when HTML audio is unavailable', () => {
    delete global.Audio;
    expect(() => playNotifySound()).not.toThrow();
  });
});
