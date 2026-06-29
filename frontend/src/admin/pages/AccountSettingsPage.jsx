import React, { useState, useEffect, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { useAuth } from '../context/AuthContext';
import { accountAPI, resolveStorageUrl } from '../services/api';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/airking_ds.css';
import '../styles/account_settings.css';

const EMAIL_DOMAIN = '@airking.com';

const ROLE_TONE = {
  admin: 'red',
  branch_manager: 'blue',
  warehouse_personnel: 'amber',
  inventory_analyst: 'violet',
  auditor: 'gray',
};

/* line icons in the app's stroke-2 style */
const CameraIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
    <circle cx="12" cy="13" r="4" />
  </svg>
);
const TrashIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
  </svg>
);
const LockIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
);

const ManagedNote = () => (
  <span className="as-managed"><LockIcon /> Managed by your administrator</span>
);

const AccountSettingsPage = () => {
  const { user, updateUser, refreshUser } = useAuth();
  const fileRef = useRef(null);

  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState(null);

  const [info, setInfo] = useState({ first_name: '', last_name: '', phone: '', address: '' });
  const [emailLocal, setEmailLocal] = useState('');
  const [emailPw, setEmailPw] = useState('');
  const [pw, setPw] = useState({ current_password: '', new_password: '', new_password_confirmation: '' });

  // Inline, field-level validation messages (toasts carry the headline).
  const [emailErr, setEmailErr] = useState({ local: '', pw: '' });
  const [pwErr, setPwErr] = useState({ current: '', next: '' });

  const [busy, setBusy] = useState({});

  const load = async () => {
    try {
      const res = await accountAPI.getProfile();
      const d = res.data;
      setProfile(d);
      setInfo({
        first_name: d.first_name || '',
        last_name:  d.last_name  || '',
        phone:      d.phone      || '',
        address:    d.address    || '',
      });
      setEmailLocal(d.email_local || '');
    } catch {
      toast.error("Couldn't load your account", 'Refresh the page to try again.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const syncSession = (data) => {
    if (data) updateUser({ ...user, ...data });
    refreshUser();
  };

  const saveInfo = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, info: true }));
    try {
      const res = await accountAPI.updateProfile(info);
      setProfile(res.data);
      syncSession({
        first_name: res.data.first_name,
        last_name:  res.data.last_name,
        phone:      res.data.phone,
        address:    res.data.address,
      });
      toast.success('Profile saved');
    } catch (err) {
      toast.error("Couldn't save profile", err.message);
    } finally {
      setBusy((b) => ({ ...b, info: false }));
    }
  };

  const onPickFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setBusy((b) => ({ ...b, avatar: true }));
    try {
      const res = await accountAPI.uploadAvatar(file);
      setProfile(res.data);
      syncSession({ avatar_url: res.data.avatar_url, avatar_path: res.data.avatar_path });
      toast.success('Photo updated');
    } catch (err) {
      toast.error('Upload failed', err.errors?.avatar?.[0] || err.message);
    } finally {
      setBusy((b) => ({ ...b, avatar: false }));
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const removeAvatar = async () => {
    const ok = await confirmAction({
      title: 'Remove profile photo?',
      text: 'Your initials will be shown instead.',
      confirmText: 'Remove',
      tone: 'danger',
    });
    if (!ok) return;
    setBusy((b) => ({ ...b, avatar: true }));
    try {
      const res = await accountAPI.deleteAvatar();
      setProfile(res.data);
      syncSession({ avatar_url: null, avatar_path: null });
      toast.success('Photo removed');
    } catch (err) {
      toast.error("Couldn't remove photo", err.message);
    } finally {
      setBusy((b) => ({ ...b, avatar: false }));
    }
  };

  const saveEmail = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, email: true }));
    setEmailErr({ local: '', pw: '' });
    try {
      const res = await accountAPI.updateEmail({ email_local: emailLocal, current_password: emailPw });
      setProfile(res.data);
      setEmailPw('');
      syncSession({ email: res.data.email });
      toast.success('Email updated', res.data.email);
    } catch (err) {
      // 422 with no field errors == wrong current password (controller returns a message).
      const wrongPw = err.status === 422 && !err.errors;
      setEmailErr({
        local: err.errors?.email_local?.[0] || '',
        pw: wrongPw ? err.message : (err.errors?.current_password?.[0] || ''),
      });
      toast.error("Couldn't update email", err.errors?.email_local?.[0] || err.message);
    } finally {
      setBusy((b) => ({ ...b, email: false }));
    }
  };

  const savePassword = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, password: true }));
    setPwErr({ current: '', next: '' });
    try {
      await accountAPI.updatePassword(pw);
      setPw({ current_password: '', new_password: '', new_password_confirmation: '' });
      toast.success('Password changed');
    } catch (err) {
      const wrongPw = err.status === 422 && !err.errors;
      setPwErr({
        current: wrongPw ? err.message : (err.errors?.current_password?.[0] || ''),
        next: err.errors?.new_password?.[0] || '',
      });
      toast.error("Couldn't change password", err.errors?.new_password?.[0] || err.message);
    } finally {
      setBusy((b) => ({ ...b, password: false }));
    }
  };

  if (loading) {
    return (
      <AdminLayout>
        <div className="ak-page"><Loader variant="page" label="Loading account" /></div>
      </AdminLayout>
    );
  }

  const initials =
    (info.first_name && info.last_name)
      ? (info.first_name.charAt(0) + info.last_name.charAt(0)).toUpperCase()
      : (profile?.username?.charAt(0)?.toUpperCase() || 'U');

  const roleName = profile?.role?.role_name || '';
  const roleTone = ROLE_TONE[roleName] || 'gray';
  const roleLabel = roleName ? roleName.replace(/_/g, ' ') : 'User';

  const policy = profile?.policy || { avatar: true, email: true, phone: true, address: true };

  return (
    <AdminLayout>
      <div className="ak-page" style={{ maxWidth: 860, margin: '0 auto', padding: '1.75rem 1rem 0' }}>
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Account</p>
              <h1 className="ak-title">Account Settings</h1>
              <p className="ak-sub">Manage your photo, contact details, sign-in email, and password.</p>
            </div>
          </div>
        </header>

        {/* Identity */}
        <section className="ak-card">
          <div className="as-identity">
            <div className="as-avatar">
              <div className="as-avatar-inner">
                {profile?.avatar_url
                  ? <img src={resolveStorageUrl(profile.avatar_url)} alt="" />
                  : initials}
              </div>
            </div>
            <div className="as-identity-main">
              <span className="as-identity-name">
                {info.first_name || info.last_name
                  ? `${info.first_name} ${info.last_name}`.trim()
                  : (profile?.username || 'Your account')}
              </span>
              <div className="as-identity-row">
                <span className={`ak-badge ak-badge--${roleTone}`}>{roleLabel}</span>
                <span className="as-identity-handle">{profile?.email}</span>
              </div>
            </div>
            <div className="as-identity-actions">
              {policy.avatar ? (
                <>
                  <input ref={fileRef} type="file" accept="image/*" onChange={onPickFile} style={{ display: 'none' }} />
                  <button type="button" className="ak-btn ak-btn--primary" disabled={busy.avatar} onClick={() => fileRef.current?.click()}>
                    {busy.avatar ? <span className="ak-spin" /> : <CameraIcon />}
                    {profile?.avatar_url ? 'Change photo' : 'Upload photo'}
                  </button>
                  {profile?.avatar_url && (
                    <button type="button" className="ak-btn ak-btn--danger" disabled={busy.avatar} onClick={removeAvatar}>
                      <TrashIcon /> Remove
                    </button>
                  )}
                </>
              ) : (
                <ManagedNote />
              )}
            </div>
          </div>
        </section>

        {/* Personal information */}
        <section className="ak-card">
          <div className="ak-card-head">
            <div>
              <h2 className="ak-card-title">Personal information</h2>
              <p className="ak-card-meta">Your name and how the team can reach you.</p>
            </div>
          </div>
          <form onSubmit={saveInfo}>
            <div className="as-grid">
              <div className="ak-field">
                <label className="ak-label" htmlFor="first_name">First name</label>
                <input id="first_name" className="ak-input" value={info.first_name}
                  onChange={(e) => setInfo({ ...info, first_name: e.target.value })} required />
              </div>
              <div className="ak-field">
                <label className="ak-label" htmlFor="last_name">Last name</label>
                <input id="last_name" className="ak-input" value={info.last_name}
                  onChange={(e) => setInfo({ ...info, last_name: e.target.value })} required />
              </div>
              <div className="ak-field">
                <label className="ak-label" htmlFor="phone">Phone</label>
                <input id="phone" className="ak-input" value={info.phone} disabled={!policy.phone}
                  onChange={(e) => setInfo({ ...info, phone: e.target.value })} placeholder="e.g. 0917 123 4567" />
                {!policy.phone && <ManagedNote />}
              </div>
              <div className="ak-field as-col-full">
                <label className="ak-label" htmlFor="address">Address</label>
                <input id="address" className="ak-input" value={info.address} disabled={!policy.address}
                  onChange={(e) => setInfo({ ...info, address: e.target.value })} placeholder="Street, city" />
                {!policy.address && <ManagedNote />}
              </div>
            </div>
            <div className="as-card-foot">
              <button type="submit" className="ak-btn ak-btn--primary" disabled={busy.info}>
                {busy.info && <span className="ak-spin" />}{busy.info ? 'Saving' : 'Save changes'}
              </button>
            </div>
          </form>
        </section>

        {/* Sign-in email */}
        <section className="ak-card">
          <div className="ak-card-head">
            <div>
              <h2 className="ak-card-title">Sign-in email</h2>
              <p className="ak-card-meta">Used to log in. The {EMAIL_DOMAIN} domain can't be changed.</p>
            </div>
          </div>
          <form onSubmit={saveEmail}>
            <div className="as-grid as-grid--single">
              <div className="ak-field">
                <label className="ak-label" htmlFor="email_local">Email</label>
                <div className="as-emailgroup">
                  <input id="email_local" className="ak-input" value={emailLocal} disabled={!policy.email}
                    onChange={(e) => setEmailLocal(e.target.value)} autoComplete="username" required />
                  <span className="as-emaildomain"><LockIcon />{EMAIL_DOMAIN}</span>
                </div>
                {!policy.email && <ManagedNote />}
                {emailErr.local && <span className="as-error">{emailErr.local}</span>}
              </div>
              <div className="ak-field">
                <label className="ak-label" htmlFor="email_current_password">Current password</label>
                <input id="email_current_password" className="ak-input" type="password" value={emailPw} disabled={!policy.email}
                  onChange={(e) => setEmailPw(e.target.value)} autoComplete="current-password" required={policy.email} />
                {emailErr.pw && <span className="as-error">{emailErr.pw}</span>}
              </div>
            </div>
            <div className="as-card-foot">
              <button type="submit" className="ak-btn ak-btn--primary" disabled={busy.email || !policy.email}>
                {busy.email && <span className="ak-spin" />}{busy.email ? 'Saving' : 'Update email'}
              </button>
            </div>
          </form>
        </section>

        {/* Password */}
        <section className="ak-card">
          <div className="ak-card-head">
            <div>
              <h2 className="ak-card-title">Password</h2>
              <p className="ak-card-meta">Use at least 8 characters with mixed case, a number, and a symbol.</p>
            </div>
          </div>
          <form onSubmit={savePassword}>
            <div className="as-grid as-grid--single">
              <div className="ak-field">
                <label className="ak-label" htmlFor="pw_current">Current password</label>
                <input id="pw_current" className="ak-input" type="password" value={pw.current_password}
                  onChange={(e) => setPw({ ...pw, current_password: e.target.value })} autoComplete="current-password" required />
                {pwErr.current && <span className="as-error">{pwErr.current}</span>}
              </div>
              <div className="ak-field">
                <label className="ak-label" htmlFor="pw_new">New password</label>
                <input id="pw_new" className="ak-input" type="password" value={pw.new_password}
                  onChange={(e) => setPw({ ...pw, new_password: e.target.value })} autoComplete="new-password" required />
                {pwErr.next && <span className="as-error">{pwErr.next}</span>}
              </div>
              <div className="ak-field">
                <label className="ak-label" htmlFor="pw_confirm">Confirm new password</label>
                <input id="pw_confirm" className="ak-input" type="password" value={pw.new_password_confirmation}
                  onChange={(e) => setPw({ ...pw, new_password_confirmation: e.target.value })} autoComplete="new-password" required />
              </div>
            </div>
            <div className="as-card-foot">
              <button type="submit" className="ak-btn ak-btn--primary" disabled={busy.password}>
                {busy.password && <span className="ak-spin" />}{busy.password ? 'Saving' : 'Change password'}
              </button>
            </div>
          </form>
        </section>
      </div>
    </AdminLayout>
  );
};

export default AccountSettingsPage;
