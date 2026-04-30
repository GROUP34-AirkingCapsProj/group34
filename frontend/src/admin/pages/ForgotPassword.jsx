import React from 'react';
import { useNavigate } from 'react-router-dom';
import '../styles/login.css';

const ForgotPassword = () => {
  const navigate = useNavigate();

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
            <h2>Password Reset</h2>
            <p>
              For security reasons, password resets must be handled by your
              <strong> System Administrator</strong>.
            </p>
            <p style={{ marginTop: 12, color: '#555' }}>
              Please contact your System Administrator and request a password reset.
              You will be issued a temporary password through a secure channel, which
              you must change immediately upon first login.
            </p>

            <button
              type="button"
              className="login-btn"
              style={{ marginTop: 24 }}
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
