import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { authAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/login.css';

const ForgotPassword = () => {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [temporaryPassword, setTemporaryPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setTemporaryPassword('');
    try {
      const res = await authAPI.forgotPassword(email);
      const temp = res.temporary_password;
      setTemporaryPassword(temp || '');
      toast.success('Temporary password generated. Use it to log in.');
    } catch (err) {
      const msg =
        err?.errors?.email?.[0] ||
        err.message ||
        'Failed to reset password.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
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
            <p>Enter your registered email to get a temporary password.</p>

            <form onSubmit={handleSubmit}>
              <div className="form-group">
                <label>Email</label>
                <input
                  type="email"
                  placeholder="name@example.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  autoComplete="email"
                  required
                />
              </div>

              <button type="submit" className="login-btn" disabled={submitting}>
                {submitting ? 'Processing...' : 'Generate Temporary Password'}
              </button>

              {temporaryPassword && (
                <div className="temp-password-box">
                  <p>Your temporary password:</p>
                  <code style={{ fontSize: 16 }}>{temporaryPassword}</code>
                  <p style={{ marginTop: 8 }}>
                    Use this password to log in, then ask an admin to change it or update it from your profile screen
                    if that is available.
                  </p>
                </div>
              )}
            </form>

            <button
              type="button"
              className="forgot"
              style={{ marginTop: 16 }}
              onClick={() => navigate('/admin/login')}
            >
              Back to Login
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;

