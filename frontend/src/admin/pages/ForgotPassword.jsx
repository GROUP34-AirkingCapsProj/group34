import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { passwordResetAPI } from '../services/api';
import { toast } from '../utils/toast';
import PasswordFieldWithToggle from '../components/PasswordFieldWithToggle';
import '../styles/login.css';

const ForgotPassword = () => {
  const navigate = useNavigate();
  /** 'otp' = email OTP flow; 'admin' = legacy RST- authorization code */
  const [mode, setMode] = useState('otp');
  const [step, setStep] = useState(1);
  const [email, setEmail] = useState('');
  const [otp, setOtp] = useState('');
  const [resetToken, setResetToken] = useState('');
  const [adminCode, setAdminCode] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const clearError = () => setError('');

  const handleRequestOtp = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const res = await passwordResetAPI.forgot({ email: email.trim() });
      toast.success(res?.message || 'Reset code sent to your email.');
      setStep(2);
    } catch (err) {
      setError(
        err.errors?.email?.[0]
        || err.message
        || 'Could not send reset code.'
      );
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyOtp = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const res = await passwordResetAPI.verifyResetOtp({
        email: email.trim(),
        otp: String(otp || '').trim(),
      });
      if (!res?.reset_token) {
        throw new Error('Verification succeeded but no reset token was returned.');
      }
      setResetToken(res.reset_token);
      toast.success('Code verified.');
      setStep(3);
    } catch (err) {
      setError(
        err.errors?.otp?.[0]
        || err.errors?.email?.[0]
        || err.message
        || 'Invalid or expired code.'
      );
    } finally {
      setLoading(false);
    }
  };

  const handleResetPassword = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await passwordResetAPI.resetPassword({
        email: email.trim(),
        reset_token: resetToken,
        password,
        password_confirmation: passwordConfirmation,
      });
      toast.success('Password reset. You can log in with your new password.');
      navigate('/admin/login', { replace: true });
    } catch (err) {
      setError(
        err.errors?.reset_token?.[0]
        || err.errors?.password?.[0]
        || err.errors?.password_confirmation?.[0]
        || err.errors?.email?.[0]
        || err.message
        || 'Could not reset password.'
      );
    } finally {
      setLoading(false);
    }
  };

  const handleAdminCodeReset = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await passwordResetAPI.complete({
        email: email.trim(),
        code: adminCode,
        password,
        password_confirmation: passwordConfirmation,
      });
      toast.success('Password reset. You can log in with your new password.');
      navigate('/admin/login', { replace: true });
    } catch (err) {
      setError(
        err.errors?.code?.[0]
        || err.errors?.email?.[0]
        || err.errors?.password?.[0]
        || err.errors?.password_confirmation?.[0]
        || err.message
        || 'Could not reset password.'
      );
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
            <h2>Forgot Password</h2>

            {mode === 'otp' && step === 1 && (
              <>
                <p>
                  Enter your account email. If an account exists, we'll send a
                  6-digit reset code to the recovery email on file.
                </p>
                <form onSubmit={handleRequestOtp}>
                  <div className="form-group">
                    <label htmlFor="forgot-email">Email</label>
                    <input
                      id="forgot-email"
                      name="email"
                      type="email"
                      autoComplete="email"
                      placeholder="name@example.com"
                      value={email}
                      onChange={(e) => { setEmail(e.target.value); clearError(); }}
                      maxLength={100}
                      required
                    />
                  </div>

                  <button type="submit" className="login-btn" disabled={loading}>
                    {loading ? 'Sending…' : 'Send reset code'}
                  </button>
                  {error && <p className="error">{error}</p>}
                </form>
              </>
            )}

            {mode === 'otp' && step === 2 && (
              <>
                <p>Enter the 6-digit code sent to your email.</p>
                <form onSubmit={handleVerifyOtp}>
                  <div className="form-group">
                    <label htmlFor="forgot-otp">Reset code</label>
                    <input
                      id="forgot-otp"
                      name="otp"
                      type="text"
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      placeholder="6-digit code"
                      value={otp}
                      onChange={(e) => {
                        setOtp(e.target.value.replace(/\D/g, '').slice(0, 6));
                        clearError();
                      }}
                      maxLength={6}
                      required
                    />
                  </div>

                  <button type="submit" className="login-btn" disabled={loading || otp.length !== 6}>
                    {loading ? 'Verifying…' : 'Verify code'}
                  </button>
                  {error && <p className="error">{error}</p>}

                  <button
                    type="button"
                    className="login-otp-back"
                    onClick={() => { setStep(1); setOtp(''); setResetToken(''); clearError(); }}
                  >
                    Use a different email
                  </button>
                </form>
              </>
            )}

            {mode === 'otp' && step === 3 && (
              <>
                <p>Choose a new password for your account.</p>
                <form onSubmit={handleResetPassword}>
                  <div className="form-group">
                    <label htmlFor="forgot-password">New password</label>
                    <PasswordFieldWithToggle
                      id="forgot-password"
                      name="password"
                      value={password}
                      onChange={(e) => { setPassword(e.target.value); clearError(); }}
                      placeholder="Minimum 8 characters"
                      autoComplete="new-password"
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label htmlFor="forgot-password-confirm">Confirm new password</label>
                    <PasswordFieldWithToggle
                      id="forgot-password-confirm"
                      name="password_confirmation"
                      value={passwordConfirmation}
                      onChange={(e) => { setPasswordConfirmation(e.target.value); clearError(); }}
                      placeholder="Re-enter new password"
                      autoComplete="new-password"
                      required
                    />
                  </div>

                  <button type="submit" className="login-btn" disabled={loading || !resetToken}>
                    {loading ? 'Resetting…' : 'Reset password'}
                  </button>
                  {error && <p className="error">{error}</p>}
                </form>
              </>
            )}

            {mode === 'admin' && (
              <>
                <p>
                  Enter the reset code your administrator generated in System Configuration, then choose a new password.
                </p>
                <form onSubmit={handleAdminCodeReset}>
                  <div className="form-group">
                    <label htmlFor="forgot-email-admin">Email</label>
                    <input
                      id="forgot-email-admin"
                      name="email"
                      type="email"
                      autoComplete="email"
                      placeholder="name@example.com"
                      value={email}
                      onChange={(e) => { setEmail(e.target.value); clearError(); }}
                      maxLength={100}
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label htmlFor="forgot-code">Authorization code</label>
                    <input
                      id="forgot-code"
                      name="code"
                      type="text"
                      autoComplete="one-time-code"
                      placeholder="RST-XXXXXXXX"
                      value={adminCode}
                      onChange={(e) => { setAdminCode(e.target.value); clearError(); }}
                      maxLength={40}
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label htmlFor="forgot-password-admin">New password</label>
                    <PasswordFieldWithToggle
                      id="forgot-password-admin"
                      name="password"
                      value={password}
                      onChange={(e) => { setPassword(e.target.value); clearError(); }}
                      placeholder="Minimum 8 characters"
                      autoComplete="new-password"
                      required
                    />
                  </div>

                  <div className="form-group">
                    <label htmlFor="forgot-password-confirm-admin">Confirm new password</label>
                    <PasswordFieldWithToggle
                      id="forgot-password-confirm-admin"
                      name="password_confirmation"
                      value={passwordConfirmation}
                      onChange={(e) => { setPasswordConfirmation(e.target.value); clearError(); }}
                      placeholder="Re-enter new password"
                      autoComplete="new-password"
                      required
                    />
                  </div>

                  <button type="submit" className="login-btn" disabled={loading}>
                    {loading ? 'Resetting…' : 'Reset password'}
                  </button>
                  {error && <p className="error">{error}</p>}
                </form>
              </>
            )}

            <button type="button" className="login-back-link" onClick={() => navigate('/admin/login')}>
              Back to Login
            </button>

            <button
              type="button"
              className="login-otp-back"
              onClick={() => {
                setMode(mode === 'otp' ? 'admin' : 'otp');
                setStep(1);
                setResetToken('');
                setError('');
              }}
            >
              {mode === 'otp' ? 'Use admin authorization code (RST-)' : 'Use email reset code'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;
