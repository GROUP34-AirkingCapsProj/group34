import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { accountAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import PasswordFieldWithToggle from '../components/PasswordFieldWithToggle';
import '../styles/login.css';

/**
 * Terminal screen for users holding an admin-assigned temporary password.
 * Deliberately has no sidebar or nav: the API gates every other route until
 * this form succeeds, so offering navigation would only produce 403s.
 */
const ChangePassword = () => {
  const navigate = useNavigate();
  const { refreshUser } = useAuth();

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');

    if (newPassword !== confirmation) {
      setError('The new passwords do not match.');
      return;
    }

    setLoading(true);
    try {
      await accountAPI.updatePassword({
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: confirmation,
      });
      // Re-read /me so must_change_password flips false before RoleRoute runs
      // again — otherwise the guard bounces the user straight back here.
      await refreshUser();
      toast.success('Password updated. Welcome aboard.');
      navigate('/admin/dashboard', { replace: true });
    } catch (err) {
      setError(
        err.errors?.new_password?.[0]
        || err.errors?.current_password?.[0]
        || err.message
        || 'Could not change your password.'
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
            <h2>Choose Your Password</h2>
            <p>
              Your account is using a temporary password set by an administrator.
              Choose your own password to continue.
            </p>

            <form onSubmit={handleSubmit}>
              <div className="form-group">
                <label htmlFor="current-password">Temporary Password</label>
                <PasswordFieldWithToggle
                  id="current-password"
                  name="current_password"
                  value={currentPassword}
                  onChange={(e) => { setCurrentPassword(e.target.value); setError(''); }}
                  autoComplete="current-password"
                  placeholder="The password you were given"
                  required
                />
              </div>

              <div className="form-group">
                <label htmlFor="new-password">New Password</label>
                <PasswordFieldWithToggle
                  id="new-password"
                  name="new_password"
                  value={newPassword}
                  onChange={(e) => { setNewPassword(e.target.value); setError(''); }}
                  placeholder="At least 8 characters"
                  required
                />
                <small className="pwd-rules">
                  Must be 8+ characters with upper and lower case, a number, and a symbol.
                </small>
              </div>

              <div className="form-group">
                <label htmlFor="confirm-password">Confirm New Password</label>
                <PasswordFieldWithToggle
                  id="confirm-password"
                  name="new_password_confirmation"
                  value={confirmation}
                  onChange={(e) => { setConfirmation(e.target.value); setError(''); }}
                  placeholder="Re-enter the new password"
                  required
                />
              </div>

              {/* `error`, not `error-message` — login.css:271 defines .error,
                  and ForgotPassword.jsx renders its errors the same way. */}
              {error && <p className="error">{error}</p>}

              <button type="submit" className="login-btn" disabled={loading}>
                {loading ? 'Saving…' : 'Set Password'}
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ChangePassword;
