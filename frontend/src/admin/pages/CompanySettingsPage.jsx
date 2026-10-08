import React, { useState, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { companySettingsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';

const CompanySettingsPage = () => {
  const [form, setForm] = useState({
    company_name:    '',
    company_address: '',
    company_email:   '',
    company_phone:   '',
    pdf_paper_size:  'a4',
  });
  const [loading,  setLoading]  = useState(true);
  const [saving,   setSaving]   = useState(false);

  useEffect(() => {
    companySettingsAPI.get()
      .then(data => {
        setForm({
          company_name:    data.company_name    || '',
          company_address: data.company_address || '',
          company_email:   data.company_email   || '',
          company_phone:   data.company_phone   || '',
          pdf_paper_size:  data.pdf_paper_size  || 'a4',
        });
      })
      .catch(() => toast.error('Failed to load company settings.'))
      .finally(() => setLoading(false));
  }, []);

  const handleChange = (field, value) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await companySettingsAPI.update(form);
      toast.success('Settings saved successfully!');
    } catch {
      toast.error('Failed to save settings. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <AdminLayout>
      <div className="ak-page" style={{ maxWidth: 600, margin: '0 auto', padding: '24px 16px' }}>

        {/* Header */}
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">System · Company</p>
              <h1 className="ak-title">Company Settings</h1>
              <p className="ak-sub">This information appears on the header of all exported Purchase Order documents.</p>
            </div>
          </div>
        </header>

        {loading ? (
          <Loader />
        ) : (
          <form onSubmit={handleSubmit}>
            <div style={{ background: 'var(--bg-card)', border: '1px solid var(--border-color)', borderRadius: 12, padding: '24px' }}>

              <Field label="Company Name" hint="Shown bold and large at the top of the PO document.">
                <input
                  type="text"
                  className="po-input"
                  value={form.company_name}
                  onChange={e => handleChange('company_name', e.target.value)}
                  placeholder="e.g. Airking Air Conditioning"
                  required
                />
              </Field>

              <Field label="Company Address">
                <textarea
                  className="po-textarea"
                  rows={2}
                  value={form.company_address}
                  onChange={e => handleChange('company_address', e.target.value)}
                  placeholder="e.g. CDO Warehouse A, Cagayan de Oro"
                />
              </Field>

              <Field label="Company Email">
                <input
                  type="email"
                  className="po-input"
                  value={form.company_email}
                  onChange={e => handleChange('company_email', e.target.value)}
                  placeholder="e.g. cdoairking@gmail.com"
                />
              </Field>

              <Field label="Company Phone">
                <input
                  type="text"
                  className="po-input"
                  value={form.company_phone}
                  onChange={e => handleChange('company_phone', e.target.value)}
                  placeholder="e.g. 0934324564"
                />
              </Field>

              <Field label="Default PDF Paper Size" hint="Used for automatic PO and GRN PDF downloads." last>
                <select
                  className="po-input"
                  value={form.pdf_paper_size}
                  onChange={e => handleChange('pdf_paper_size', e.target.value)}
                >
                  <option value="a4">A4</option>
                  <option value="letter">Letter</option>
                  <option value="legal">Legal</option>
                  <option value="a5">A5</option>
                </select>
              </Field>
            </div>

            {/* Preview */}
            <div style={{ background: 'var(--bg-muted)', border: '1px solid var(--border-color)', borderRadius: 10, padding: '20px 24px', marginTop: 16, fontFamily: "'Times New Roman', serif" }}>
              <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--ink-blue-300)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 10 }}>Preview — PO Document Header</div>
              <div style={{ textAlign: 'center', borderBottom: '2px solid #1e293b', paddingBottom: 10 }}>
                <div style={{ fontSize: 20, fontWeight: 900, textTransform: 'uppercase', marginBottom: 4 }}>{form.company_name || 'Company Name'}</div>
                {form.company_address && <div style={{ fontSize: 12, color: 'var(--ink-blue-700)' }}>{form.company_address}</div>}
                {form.company_email   && <div style={{ fontSize: 12, color: 'var(--ink-blue-700)' }}>{form.company_email}</div>}
              </div>
            </div>

            <button
              type="submit"
              disabled={saving}
              style={{
                marginTop: 20,
                width: '100%',
                background: saving ? '#94a3b8' : '#dc2626',
                color: '#fff',
                border: 'none',
                borderRadius: 8,
                padding: '12px 24px',
                fontSize: 14,
                fontWeight: 700,
                cursor: saving ? 'not-allowed' : 'pointer',
                letterSpacing: '0.04em',
              }}
            >
              {saving ? 'SAVING…' : 'SAVE COMPANY SETTINGS'}
            </button>
          </form>
        )}
      </div>
    </AdminLayout>
  );
};

const Field = ({ label, hint, last, children }) => (
  <div style={{ marginBottom: last ? 0 : 18 }}>
    <label style={{ fontSize: 10, fontWeight: 700, color: 'var(--ink-blue-500)', textTransform: 'uppercase', letterSpacing: '0.06em', display: 'block', marginBottom: 5 }}>
      {label}
    </label>
    {children}
    {hint && <span style={{ fontSize: 11, color: 'var(--ink-blue-300)', marginTop: 3, display: 'block' }}>{hint}</span>}
  </div>
);

export default CompanySettingsPage;
