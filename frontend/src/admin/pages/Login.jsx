import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { authAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import '../styles/login.css';

/** neutral while typing a valid prefix of the answer; wrong once impossible to match */
function captchaAnswerStatus(answer, expected) {
  const trimmed = String(answer || '').replace(/\s/g, '');
  if (!trimmed) return 'neutral';
  if (!/^\d+$/.test(trimmed)) return 'wrong';
  const n = parseInt(trimmed, 10);
  if (n === expected) return 'correct';
  const expStr = String(expected);
  if (expStr.startsWith(trimmed) && trimmed.length < expStr.length) return 'neutral';
  return 'wrong';
}

const Login = () => {
  const navigate = useNavigate();
  const { updateUser } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [captchaAnswer, setCaptchaAnswer] = useState('');
  const [captchaToken, setCaptchaToken] = useState('');
  const [captchaQuestion, setCaptchaQuestion] = useState('');
  const [captchaA, setCaptchaA] = useState(null);
  const [captchaB, setCaptchaB] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [challengeLoading, setChallengeLoading] = useState(true);

  const expectedSum = useMemo(() => {
    if (captchaA == null || captchaB == null) return null;
    return captchaA + captchaB;
  }, [captchaA, captchaB]);

  const captchaStatus = useMemo(() => {
    if (expectedSum == null) return 'neutral';
    return captchaAnswerStatus(captchaAnswer, expectedSum);
  }, [captchaAnswer, expectedSum]);

  const loadChallenge = useCallback(async () => {
    setChallengeLoading(true);
    try {
      const res = await authAPI.getLoginChallenge();
      const d = res?.data ?? res;
      setCaptchaToken(d?.captcha_token || '');
      setCaptchaQuestion(d?.question || '');
      let a = d?.operand_a;
      let b = d?.operand_b;
      if ((a == null || b == null) && d?.question) {
        const m = String(d.question).match(/What is (\d+) \+ (\d+)\?/);
        if (m) {
          a = parseInt(m[1], 10);
          b = parseInt(m[2], 10);
        }
      }
      setCaptchaA(typeof a === 'number' && !Number.isNaN(a) ? a : null);
      setCaptchaB(typeof b === 'number' && !Number.isNaN(b) ? b : null);
      setCaptchaAnswer('');
    } catch (e) {
      setCaptchaQuestion('');
      setCaptchaToken('');
      setCaptchaA(null);
      setCaptchaB(null);
      toast.error(e.message || 'Could not load security challenge. Check API connection.');
    } finally {
      setChallengeLoading(false);
    }
  }, []);

  useEffect(() => {
    loadChallenge();
  }, [loadChallenge]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    const challengeReady =
      captchaToken && ((captchaA != null && captchaB != null) || !!captchaQuestion);
    if (!challengeReady) {
      setError('Security challenge not ready. Refresh the page.');
      setLoading(false);
      await loadChallenge();
      return;
    }

    try {
      const data = await authAPI.login(email, password, captchaToken, captchaAnswer);
      if (data.user) updateUser(data.user);
      navigate('/admin/dashboard');
    } catch (err) {
      const message =
        err.errors?.captcha_answer?.[0]
        || err.errors?.email?.[0]
        || err.message
        || 'Invalid email or password.';
      setError(message);
      setPassword('');
      setCaptchaAnswer('');
      await loadChallenge();
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <div className="login-container">
        <div className="left-side">
          <div className="logo-box">
            <img src="/images/air.png" alt="AirKing Logo" className="logo" />
          </div>
          <h1>AirKing Air Conditioning</h1>
          <p className="description">
            WEB-BASED ADAPTIVE BARCODE AUDITING AND INVENTORY MANAGEMENT SYSTEM FOR AIRKING AIR CONDITIONING
          </p>
        </div>

        <div className="right-side">
          <div className="login-form">
            <h2>Welcome Back !</h2>
            <p>Please login to your account</p>

            <form onSubmit={handleSubmit}>
              <div className="form-group">
                <label>Email</label>
                <input
                  type="email"
                  placeholder="name@example.com"
                  value={email}
                  onChange={(e) => {
                    setEmail(e.target.value);
                    setError('');
                  }}
                  autoComplete="email"
                  maxLength={100}
                  required
                />
              </div>

              <div className="form-group">
                <label>Password</label>
                <input
                  type="password"
                  placeholder="Enter your password"
                  value={password}
                  onChange={(e) => {
                    setPassword(e.target.value);
                    setError('');
                  }}
                  autoComplete="current-password"
                  maxLength={255}
                  required
                />
              </div>

              <div className="form-group">
                <label>Security check</label>
                {challengeLoading ? (
                  <p className="login-challenge-loading">Loading challenge…</p>
                ) : captchaA != null && captchaB != null ? (
                  <div
                    className={
                      'login-captcha-row' +
                      (captchaStatus === 'correct' ? ' login-captcha-row--correct' : '') +
                      (captchaStatus === 'wrong' ? ' login-captcha-row--wrong' : '')
                    }
                  >
                    <span className="login-captcha-num" aria-hidden="true">
                      {captchaA}
                    </span>
                    <span className="login-captcha-op" aria-hidden="true">
                      +
                    </span>
                    <span className="login-captcha-num" aria-hidden="true">
                      {captchaB}
                    </span>
                    <span className="login-captcha-op" aria-hidden="true">
                      =
                    </span>
                    <input
                      type="text"
                      inputMode="numeric"
                      autoComplete="off"
                      placeholder=""
                      aria-label="Security check answer"
                      className={
                        'login-captcha-answer' +
                        (captchaStatus === 'correct' ? ' login-captcha-answer--correct' : '') +
                        (captchaStatus === 'wrong' ? ' login-captcha-answer--wrong' : '')
                      }
                      value={captchaAnswer}
                      onChange={(e) => {
                        setCaptchaAnswer(e.target.value.replace(/\D/g, ''));
                        setError('');
                      }}
                      maxLength={4}
                      required
                    />
                    <button
                      type="button"
                      className="login-captcha-refresh"
                      onClick={loadChallenge}
                      title="New question"
                      aria-label="New security question"
                    >
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path
                          d="M1 4v6h6M23 20v-6h-6"
                          stroke="currentColor"
                          strokeWidth="2"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                        <path
                          d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"
                          stroke="currentColor"
                          strokeWidth="2"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </button>
                  </div>
                ) : captchaQuestion ? (
                  <>
                    <p className="login-captcha-question">{captchaQuestion}</p>
                    <input
                      type="text"
                      inputMode="numeric"
                      placeholder="Your answer"
                      value={captchaAnswer}
                      onChange={(e) => {
                        setCaptchaAnswer(e.target.value.replace(/\D/g, ''));
                        setError('');
                      }}
                      maxLength={10}
                      required
                    />
                    <button type="button" className="login-refresh-captcha" onClick={loadChallenge}>
                      New question
                    </button>
                  </>
                ) : (
                  <p className="login-challenge-error">Challenge unavailable. Check API / backend.</p>
                )}
              </div>

              <div className="form-options">
                <div className="remember">
                  <input type="checkbox" id="remember" />
                  <label htmlFor="remember">Remember me</label>
                </div>
                <button
                  type="button"
                  className="forgot"
                  onClick={() => navigate('/admin/forgot-password')}
                >
                  Forgot Password?
                </button>
              </div>

              <button type="submit" className="login-btn" disabled={loading || challengeLoading || !captchaToken}>
                {loading ? 'Logging in...' : 'Login'}
              </button>

              {error && <p className="error">{error}</p>}
            </form>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Login;
