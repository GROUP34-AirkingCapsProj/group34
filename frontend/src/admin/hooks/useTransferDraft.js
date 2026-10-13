import { useEffect, useRef, useState } from 'react';
import { transfersAPI } from '../services/api';

const storageKey = (userId, recordId) => `transfer-draft:${userId}:${recordId || 'new'}`;
export const readTransferDraft = (userId, recordId) => {
  try {
    const cached = JSON.parse(localStorage.getItem(storageKey(userId, recordId)) || 'null');
    return cached?.form && Array.isArray(cached.form.lines) ? cached : null;
  }
  catch { return null; }
};

// Save locally immediately, serialize server writes, and flush on navigation.
export default function useTransferDraft({ userId, recordId, isOpen, ready, form, recovered, onSaved }) {
  const [status, setStatus] = useState('');
  const session = useRef(null);
  const latest = useRef(form);
  latest.current = form;
  const callback = useRef(onSaved);
  callback.current = onSaved;
  const mounted = useRef(true);
  const timer = useRef(null);

  const writeLocal = (current) => {
    if (!current || current.completed || !current.dirty) return;
    try {
      localStorage.setItem(current.key, JSON.stringify({
        form: latest.current, draftId: current.id, draftKey: current.draftKey,
        dirty: JSON.stringify(latest.current) !== current.saved,
      }));
    } catch { if (mounted.current) setStatus('Draft could not be saved in this browser.'); }
  };

  const persist = (keepalive = false) => {
    clearTimeout(timer.current);
    const current = session.current;
    if (!current || current.completed || !current.dirty || current.baseline === null) return Promise.resolve(current?.id);
    const snapshot = latest.current;
    const serialized = JSON.stringify(snapshot);
    writeLocal(current);
    current.queue = current.queue.then(async () => {
      if (current.completed || current.saved === serialized) return current.id;
      if (mounted.current) setStatus('Saving draft…');
      try {
        const result = await transfersAPI.saveDraft(current.id, { draft_key: current.draftKey, form: snapshot }, { keepalive });
        current.id = result.data.transfer_id;
        current.saved = serialized;
        if (session.current === current) writeLocal(current);
        if (mounted.current && session.current === current) setStatus('Draft saved automatically');
        callback.current?.();
      } catch {
        if (mounted.current && session.current === current) setStatus('Draft saved in this browser. Server save will retry.');
      }
      return current.id;
    });
    return current.queue;
  };
  const persistRef = useRef(persist);
  persistRef.current = persist;

  useEffect(() => {
    if (!isOpen) return;
    const cache = recovered || readTransferDraft(userId, recordId);
    session.current = {
      key: storageKey(userId, recordId), id: recordId || cache?.draftId || null,
      draftKey: cache?.draftKey || `transfer-${userId}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      baseline: null, saved: '', dirty: !!cache?.dirty, completed: false, queue: Promise.resolve(),
    };
    setStatus('');
    return () => { persistRef.current(true); };
    // A draft session belongs to this user and record until the editor closes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, userId, recordId]);

  useEffect(() => {
    const current = session.current;
    if (!isOpen || !ready || !current || current.completed || current.paused) return;
    const serialized = JSON.stringify(form);
    if (current.baseline === null) {
      current.baseline = serialized;
      if (!current.dirty) current.saved = serialized;
    }
    if (serialized !== current.baseline) current.dirty = true;
    if (!current.dirty) return;
    writeLocal(current);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => persistRef.current(), 600);
    return () => clearTimeout(timer.current);
    // Form changes save a browser backup before scheduling the server write.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, ready, form]);

  useEffect(() => {
    mounted.current = true;
    const saveOnExit = () => persistRef.current(true);
    window.addEventListener('pagehide', saveOnExit);
    const saveWhenHidden = () => { if (document.visibilityState === 'hidden') saveOnExit(); };
    document.addEventListener('visibilitychange', saveWhenHidden);
    const retry = () => persistRef.current();
    window.addEventListener('online', retry);
    return () => {
      persistRef.current(true);
      mounted.current = false;
      window.removeEventListener('pagehide', saveOnExit);
      document.removeEventListener('visibilitychange', saveWhenHidden);
      window.removeEventListener('online', retry);
    };
  }, []);

  return {
    status,
    flush: () => persist(),
    pause: () => { if (session.current) session.current.paused = true; clearTimeout(timer.current); },
    resume: () => { if (session.current) session.current.paused = false; },
    complete: () => {
      const current = session.current;
      if (!current) return;
      current.completed = true;
      clearTimeout(timer.current);
      try {
        localStorage.removeItem(current.key);
        const newDraft = readTransferDraft(userId, null);
        if (current.id && newDraft?.draftId === current.id) localStorage.removeItem(storageKey(userId, null));
      } catch { /* The submitted request is already stored on the server. */ }
    },
  };
}
