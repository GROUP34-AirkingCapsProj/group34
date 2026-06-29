// frontend/src/admin/hooks/useFocusPulse.js
import { useCallback, useEffect, useRef, useState } from 'react';

// Returns [pulsing, firePulse]. Calling firePulse() sets pulsing=true for
// durationMs, then back to false. Used to flash a freshly focused modal.
// NOTE: the default 2000ms matches the ak-focus-pulse CSS animation duration
// (1s x 2 iterations in airking_ds.css). If you override durationMs, also update
// the animation duration/iteration count there.
export function useFocusPulse(durationMs = 2000) {
  const [pulsing, setPulsing] = useState(false);
  const timer = useRef(null);

  const firePulse = useCallback(() => {
    setPulsing(true);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => setPulsing(false), durationMs);
  }, [durationMs]);

  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);

  return [pulsing, firePulse];
}
