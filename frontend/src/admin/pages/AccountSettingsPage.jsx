import React, { useState, useEffect, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { useAuth } from '../context/AuthContext';
import { accountAPI } from '../services/api';
import '../styles/dashboard_air.css';

const EMAIL_DOMAIN = '@airking.com';

const Banner = ({ kind, children }) => {
  if (!children) return null;
  const ok = kind === 'success';
  return (
    <div style={{
      background: ok ? '#f0fdf4' : '#fef2f2',
      border: `1px solid ${ok ? '#bbf7d0' : '#fecaca'}`,
      borderRadius: 8, padding: '10px 16px', marginBottom: 16,
      fontSize: 13, color: ok ? '#15803d' : '#b91c1c',
    }}>{children}</div>
  );
};

const AccountSettingsPage = () => {
  const { user, updateUser, refreshUser } = useAuth();
  const fileRef = useRef(null);

  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState(null);

  const [info, setInfo] = useState({ first_name: '', last_name: '', phone: '', address: '' });
  const [emailLocal, setEmailLocal] = useState('');
  const [emailPw, setEmailPw] = useState('');
  const [pw, setPw] = useState({ current_password: '', new_password: '', new_password_confirmation: '' });

  const [msg, setMsg] = useState({});
  const [busy, setBusy] = useState({});

  const setSectionMsg = (section, kind, text) =>
    setMsg((m) => ({ ...m, [section]: { kind, text } }));

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
      setSectionMsg('info', 'error', 'Failed to load your account.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const syncSession = async (data) => {
    if (data) updateUser({ ...user, ...data });
    refreshUser();
  };

  const saveInfo = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, info: true }));
    setSectionMsg('info', null, '');
    try {
      const res = await accountAPI.updateProfile(info);
      setProfile(res.data);
      await syncSession({
        first_name: res.data.first_name,
        last_name:  res.data.last_name,
        phone:      res.data.phone,
        address:    res.data.address,
      });
      setSectionMsg('info', 'success', 'Profile saved.');
    } catch (err) {
      setSectionMsg('info', 'error', err.message || 'Could not save profile.');
    } finally {
      setBusy((b) => ({ ...b, info: false }));
    }
  };

  const onPickFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setBusy((b) => ({ ...b, avatar: true }));
    setSectionMsg('avatar', null, '');
    try {
      const res = await accountAPI.uploadAvatar(file);
      setProfile(res.data);
      await syncSession({ avatar_url: res.data.avatar_url, avatar_path: res.data.avatar_path });
      setSectionMsg('avatar', 'success', 'Photo updated.');
    } catch (err) {
      setSectionMsg('avatar', 'error', err.message || 'Upload failed.');
    } finally {
      setBusy((b) => ({ ...b, avatar: false }));
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const removeAvatar = async () => {
    setBusy((b) => ({ ...b, avatar: true }));
    setSectionMsg('avatar', null, '');
    try {
      const res = await accountAPI.deleteAvatar();
      setProfile(res.data);
      await syncSession({ avatar_url: null, avatar_path: null });
      setSectionMsg('avatar', 'success', 'Photo removed.');
    } catch (err) {
      setSectionMsg('avatar', 'error', err.message || 'Could not remove photo.');
    } finally {
      setBusy((b) => ({ ...b, avatar: false }));
    }
  };

  const saveEmail = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, email: true }));
    setSectionMsg('email', null, '');
    try {
      const res = await accountAPI.updateEmail({ email_local: emailLocal, current_password: emailPw });
      setProfile(res.data);
      setEmailPw('');
      await syncSession({ email: res.data.email });
      setSectionMsg('email', 'success', 'Email updated.');
    } catch (err) {
      const detail = err.errors?.email_local?.[0] || err.message;
      setSectionMsg('email', 'error', detail || 'Could not update email.');
    } finally {
      setBusy((b) => ({ ...b, email: false }));
    }
  };

  const savePassword = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, password: true }));
    setSectionMsg('password', null, '');
    try {
      await accountAPI.updatePassword(pw);
      setPw({ current_password: '', new_password: '', new_password_confirmation: '' });
      setSectionMsg('password', 'success', 'Password changed.');
    } catch (err) {
      const detail = err.errors?.new_password?.[0] || err.message;
      setSectionMsg('password', 'error', detail || 'Could not change password.');
    } finally {
      setBusy((b) => ({ ...b, password: false }));
    }
  };

  if (loading) {
    return <AdminLayout><Loader /></AdminLayout>;
  }

  const initials =
    (info.first_name && info.last_name)
      ? (info.first_name.charAt(0) + info.last_name.charAt(0)).toUpperCase()
      : (profile?.username?.charAt(0)?.toUpperCase() || 'U');

  const card = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: 20, marginBottom: 20 };
  const lbl  = { display: 'block', fontSize: 12, fontWeight: 600, color: '#374151', marginBottom: 6 };
  const inp  = { width: '100%', padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, marginBottom: 14, boxSizing: 'border-box' };
  const btn  = { padding: '8px 16px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, fontSize: 14, fontWeight: 600, cursor: 'pointer' };

  return (
    <AdminLayout>
      <div className="ak-page" style={{ maxWidth: 640, margin: '0 auto', padding: '24px 16px' }}>
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Account</p>
              <h1 className="ak-title">Account Settings</h1>
              <p className="ak-sub">Manage your photo, contact details, email, and password.</p>
            </div>
          </div>
        </header>

        {/* Profile photo */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Profile photo</h2>
          <Banner kind={msg.avatar?.kind}>{msg.avatar?.text}</Banner>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            <div style={{
              width: 72, height: 72, borderRadius: '50%', overflow: 'hidden',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              background: '#2563eb', color: '#fff', fontSize: 24, fontWeight: 700,
              flexShrink: 0,
            }}>
              {profile?.avatar_url
                ? <img src={profile.avatar_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                : initials}
            </div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <input ref={fileRef} type="file" accept="image/*" onChange={onPickFile} style={{ display: 'none' }} />
              <button type="button" style={btn} disabled={busy.avatar} onClick={() => fileRef.current?.click()}>
                {busy.avatar ? 'Uploading…' : 'Upload'}
              </button>
              {profile?.avatar_url && (
                <button type="button" style={{ ...btn, background: '#6b7280' }} disabled={busy.avatar} onClick={removeAvatar}>
                  Remove
                </button>
              )}
            </div>
          </div>
        </section>

        {/* Personal information */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Personal information</h2>
          <Banner kind={msg.info?.kind}>{msg.info?.text}</Banner>
          <form onSubmit={saveInfo}>
            <label htmlFor="first_name" style={lbl}>First name</label>
            <input id="first_name" style={inp} value={info.first_name} onChange={(e) => setInfo({ ...info, first_name: e.target.value })} required />
            <label htmlFor="last_name" style={lbl}>Last name</label>
            <input id="last_name" style={inp} value={info.last_name} onChange={(e) => setInfo({ ...info, last_name: e.target.value })} required />
            <label htmlFor="phone" style={lbl}>Phone</label>
            <input id="phone" style={inp} value={info.phone} onChange={(e) => setInfo({ ...info, phone: e.target.value })} />
            <label htmlFor="address" style={lbl}>Address</label>
            <input id="address" style={inp} value={info.address} onChange={(e) => setInfo({ ...info, address: e.target.value })} />
            <button type="submit" style={btn} disabled={busy.info}>{busy.info ? 'Saving…' : 'Save changes'}</button>
          </form>
        </section>

        {/* Email address */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Email address</h2>
          <Banner kind={msg.email?.kind}>{msg.email?.text}</Banner>
          <form onSubmit={saveEmail}>
            <label htmlFor="email_local" style={lbl}>Email</label>
            <div style={{ display: 'flex', alignItems: 'stretch', marginBottom: 14 }}>
              <input
                id="email_local"
                style={{ ...inp, marginBottom: 0, borderTopRightRadius: 0, borderBottomRightRadius: 0, flex: 1 }}
                value={emailLocal}
                onChange={(e) => setEmailLocal(e.target.value)}
                required
              />
              <span style={{
                display: 'inline-flex', alignItems: 'center', padding: '0 12px',
                background: '#f3f4f6', border: '1px solid #d1d5db', borderLeft: 'none',
                borderTopRightRadius: 8, borderBottomRightRadius: 8, fontSize: 14, color: '#6b7280',
                whiteSpace: 'nowrap',
              }}>{EMAIL_DOMAIN}</span>
            </div>
            <label htmlFor="email_current_password" style={lbl}>Current password</label>
            <input id="email_current_password" style={inp} type="password" value={emailPw} onChange={(e) => setEmailPw(e.target.value)} required />
            <button type="submit" style={btn} disabled={busy.email}>{busy.email ? 'Saving…' : 'Update email'}</button>
          </form>
        </section>

        {/* Password */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Password</h2>
          <Banner kind={msg.password?.kind}>{msg.password?.text}</Banner>
          <form onSubmit={savePassword}>
            <label htmlFor="pw_current" style={lbl}>Current password</label>
            <input id="pw_current" style={inp} type="password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} required />
            <label htmlFor="pw_new" style={lbl}>New password</label>
            <input id="pw_new" style={inp} type="password" value={pw.new_password} onChange={(e) => setPw({ ...pw, new_password: e.target.value })} required />
            <label htmlFor="pw_confirm" style={lbl}>Confirm new password</label>
            <input id="pw_confirm" style={inp} type="password" value={pw.new_password_confirmation} onChange={(e) => setPw({ ...pw, new_password_confirmation: e.target.value })} required />
            <button type="submit" style={btn} disabled={busy.password}>{busy.password ? 'Saving…' : 'Change password'}</button>
          </form>
        </section>
      </div>
    </AdminLayout>
  );
};

export default AccountSettingsPage;
