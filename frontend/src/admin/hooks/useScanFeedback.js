import { useCallback, useRef, useState } from 'react';

let audioContext;
export function signalScan(accepted) {
  try {
    const Context = window.AudioContext || window.webkitAudioContext;
    if (Context) {
      audioContext ||= new Context();
      void audioContext.resume().catch(() => {});
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      oscillator.frequency.value = accepted ? 880 : 220;
      gain.gain.setValueAtTime(0.06, audioContext.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.16);
      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.onended = () => { oscillator.disconnect(); gain.disconnect(); };
      oscillator.start();
      oscillator.stop(audioContext.currentTime + 0.17);
    }
    navigator.vibrate?.(accepted ? 60 : [80, 40, 80]);
  } catch { /* Visual feedback remains available without audio or vibration. */ }
}

export default function useScanFeedback() {
  const [feedback, setFeedback] = useState(null);
  const sequence = useRef(0);
  const reportScan = useCallback((accepted, message, barcode = '', product = '') => {
    setFeedback({ accepted, message, barcode, product, sequence: ++sequence.current });
    if (accepted === true || accepted === false) signalScan(accepted);
  }, []);
  return { feedback, reportScan };
}
