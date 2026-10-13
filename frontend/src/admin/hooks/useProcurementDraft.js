import { useCallback, useLayoutEffect, useRef, useState } from 'react';

// Save synchronously after each edit so navigation and browser exit need no request.
export function useProcurementDraft(userId, type, fields, enabled = true) {
  const key = userId == null ? null : `procurement-draft:v1:${userId}:${type}`;
  const form = Object.fromEntries(Object.entries(fields).map(([name, [value]]) => [name, value]));
  const serialized = JSON.stringify(form);
  const session = useRef(null);
  const fieldsRef = useRef(fields);
  fieldsRef.current = fields;
  const [status, setStatus] = useState('');
  const [hasDraft, setHasDraft] = useState(false);

  useLayoutEffect(() => {
    if (!key || !enabled) return;
    if (session.current?.key !== key) {
      const current = { key, initial: form, previous: serialized };
      session.current = current;
      try {
        const saved = JSON.parse(localStorage.getItem(key) || 'null');
        const valid = saved?.form && Object.keys(form).every(name =>
          Object.hasOwn(saved.form, name) &&
          (Array.isArray(form[name]) ? Array.isArray(saved.form[name]) :
            form[name] === null ? saved.form[name] === null || typeof saved.form[name] === 'object' || typeof saved.form[name] === 'string' :
              typeof saved.form[name] === typeof form[name]));
        if (valid) {
          Object.entries(fieldsRef.current).forEach(([name, [, setter]]) => setter(saved.form[name]));
          current.previous = JSON.stringify(saved.form);
          setHasDraft(true);
          setStatus('Draft restored. Changes are saved automatically in this browser.');
        }
      } catch {
        setStatus('Draft storage is unavailable. Keep this screen open to preserve your inputs.');
      }
      return;
    }
    const current = session.current;
    if (current.previous === serialized) return;
    current.previous = serialized;
    try {
      localStorage.setItem(key, JSON.stringify({ form, savedAt: new Date().toISOString() }));
      setHasDraft(true);
      setStatus('Draft saved automatically in this browser.');
    } catch {
      setStatus('Draft could not be saved. Keep this screen open to preserve your inputs.');
    }
  });

  const clear = useCallback((reset = false) => {
    const current = session.current;
    if (!current) return;
    try { localStorage.removeItem(current.key); } catch { return; }
    if (reset) {
      current.previous = JSON.stringify(current.initial);
      Object.entries(fieldsRef.current).forEach(([name, [, setter]]) => setter(current.initial[name]));
    }
    setHasDraft(false);
    setStatus('');
  }, []);

  return { status, hasDraft, clear };
}
