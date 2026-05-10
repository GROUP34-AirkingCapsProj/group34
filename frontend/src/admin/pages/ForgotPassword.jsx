import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { passwordResetAPI } from '../services/api';
import { toast } from '../utils/toast';
import PasswordFieldWithToggle from '../components/PasswordFieldWithToggle';
import '../styles/login.css';

const ForgotPassword = () => {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await passwordResetAPI.complete({
        email,
        code,
        password,
        password_confirmation: passwordConfirmation,
      });
      toast.success('Password reset. You can log in with your new password.');
      navigate('/admin/login', { replace: true });
    } catch (err) {
      const msg =
        err.errors?.code?.[0]
        || err.errors?.email?.[0]
        || err.errors?.password?.[0]
        || err.errors?.password_confirmation?.[0]
        || err.message
        || 'Could not reset password.';
      setError(msg);
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
            <p>
              Enter the reset code your administrator generated in System Configuration, then choose a new password.
            </p>

            <form onSubmit={handleSubmit}>
              <div className="form-group">
                <label htmlFor="forgot-email">Email</label>
                <input
                  id="forgot-email"
                  name="email"
                  type="email"
                  autoComplete="email"
                  placeholder="name@example.com"
                  value={email}
                  onChange={(e) => {
                    setEmail(e.target.value);
                    setError('');
                  }}
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
                  value={code}
                  onChange={(e) => {
                    setCode(e.target.value);
                    setError('');
                  }}
                  maxLength={40}
                  required
                />
              </div>

              <div className="form-group">
                <label htmlFor="forgot-password">New password</label>
                <PasswordFieldWithToggle
                  id="forgot-password"
                  name="password"
                  value={password}
                  onChange={(e) => {
                    setPassword(e.target.value);
                    setError('');
                  }}
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
                  onChange={(e) => {
                    setPasswordConfirmation(e.target.value);
                    setError('');
                  }}
                  placeholder="Re-enter new password"
                  autoComplete="new-password"
                  required
                />
              </div>

              <button type="submit" className="login-btn" disabled={loading}>
                {loading ? 'Resetting…' : 'Reset password'}
              </button>

              {error && <p className="error">{error}</p>}

              <button type="button" className="login-back-link" onClick={() => navigate('/admin/login')}>
                Back to Login
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;
