import { useEffect, useRef, useCallback } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import {
  authAPI,
  getSessionTimeoutMinutes,
  getToken,
  SESSION_TIMEOUT_CHANGED_EVENT,
} from '../services/api';
import { toast } from '../utils/toast';

const ACTIVITY_EVENTS = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click', 'wheel'];

function isLaravelAdminProtectedRoute(pathname) {
  if (!pathname.startsWith('/admin')) return false;
  if (pathname === '/admin/login' || pathname === '/admin/forgot-password') return false;
  return true;
}

/**
 * Logs out after browser inactivity for configured minutes (see system session settings).
 */
export default function SessionIdleGuard() {
  const location = useLocation();
  const navigate = useNavigate();
  const { clearSession } = useAuth();
  const timerRef = useRef(null);

  const clearTimer = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const runIdleLogout = useCallback(async () => {
    clearTimer();
    try {
      await authAPI.logout();
    } catch {
      // Token may already be invalid; still clear client session.
    }
    clearSession();
    navigate('/admin/login', { replace: true });
    toast.warning('You were logged out due to inactivity.');
  }, [clearSession, clearTimer, navigate]);

  const scheduleTimer = useCallback(() => {
    clearTimer();
    const ms = getSessionTimeoutMinutes() * 60 * 1000;
    timerRef.current = setTimeout(runIdleLogout, ms);
  }, [clearTimer, runIdleLogout]);

  useEffect(() => {
    const token = getToken();
    const active = Boolean(token) && isLaravelAdminProtectedRoute(location.pathname);

    if (!active) {
      clearTimer();
      return undefined;
    }

    const bump = () => scheduleTimer();
    bump();
    ACTIVITY_EVENTS.forEach((ev) => window.addEventListener(ev, bump, { passive: true }));

    return () => {
      ACTIVITY_EVENTS.forEach((ev) => window.removeEventListener(ev, bump));
      clearTimer();
    };
  }, [location.pathname, scheduleTimer, clearTimer]);

  // After "Save security settings" (or login), apply the new timeout to the running idle clock immediately.
  useEffect(() => {
    const onPolicyChanged = () => {
      const token = getToken();
      if (!token || !isLaravelAdminProtectedRoute(location.pathname)) return;
      scheduleTimer();
    };
    window.addEventListener(SESSION_TIMEOUT_CHANGED_EVENT, onPolicyChanged);
    return () => window.removeEventListener(SESSION_TIMEOUT_CHANGED_EVENT, onPolicyChanged);
  }, [location.pathname, scheduleTimer]);

  return null;
}
