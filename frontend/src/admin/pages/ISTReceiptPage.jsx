import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { companySettingsAPI, istAPI } from '../services/api';

const fmtDate = (v) => {
  if (!v) return '—';
  try {
    return new Date(v).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
  } catch { return String(v).slice(0, 10); }
};

const fullName = (u) =>
  u ? ((`${u.first_name || ''} ${u.last_name || ''}`).trim() || u.username || '—') : '—';

const ISTReceiptPage = () => {
  const { id }   = useParams();
  const navigate = useNavigate();

  const [ist,     setIst]     = useState(null);
  const [loading, setLoading] = useState(true);
  const [error,   setError]   = useState('');
  const [paperSize, setPaperSize] = useState('a4');

  const fetchIst = useCallback(() => {
    setLoading(true);
    istAPI.receipt(id)
      .then((r) => setIst(r?.data ?? r))
      .catch((e) => setError(e?.message || 'Receipt not available. IST must be fully received first.'))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { fetchIst(); }, [fetchIst]);
  useEffect(() => {
    companySettingsAPI.get()
      .then((settings) => setPaperSize(settings?.pdf_paper_size || 'a4'))
      .catch(() => {});
  }, []);

  const pdfUrl = istAPI.receiptPdfUrl(id, paperSize);

  return (
    <>
      {/* ── Action bar (hidden on print) ── */}
      <div className="no-print" style={{
        background: '#1e293b', padding: '10px 24px',
        display: 'flex', alignItems: 'center', gap: 12,
      }}>
        <button
          type="button"
          onClick={() => navigate(`/admin/ist/${id}`)}
          style={{
            background: 'transparent', border: '1px solid rgba(255,255,255,0.3)',
            color: '#fff', padding: '6px 14px', borderRadius: 6, cursor: 'pointer', fontSize: 12,
          }}
        >
          ← Back
        </button>
        <span style={{ color: '#fff', fontWeight: 700, fontSize: 14, flex: 1 }}>
          IST Receipt — {ist?.transfer_number || `#${id}`}
        </span>
        <button
          type="button"
          onClick={() => window.print()}
          style={{
            background: '#fff', color: '#1e293b', border: 'none',
            padding: '7px 18px', borderRadius: 6, cursor: 'pointer', fontWeight: 700, fontSize: 12,
          }}
        >
          Print
        </button>
        <select value={paperSize} onChange={(e) => setPaperSize(e.target.value)} style={{ padding: '7px 10px', borderRadius: 6 }}>
          <option value="a4">A4</option><option value="letter">Letter</option>
          <option value="legal">Legal</option><option value="a5">A5</option>
        </select>
        <a
          href={pdfUrl}
          target="_blank"
          rel="noopener noreferrer"
          style={{
            background: '#f59e0b', color: '#fff', textDecoration: 'none',
            padding: '7px 18px', borderRadius: 6, fontWeight: 700, fontSize: 12,
          }}
        >
          Download PDF
        </a>
      </div>

      {/* ── Receipt body ── */}
      <div style={{ background: '#f8fafc', minHeight: '100vh', padding: '32px 0' }}>
        {loading && (
          <Loader label="Loading receipt" />
        )}
        {error && (
          <div style={{ maxWidth: 600, margin: '40px auto', background: '#fee2e2', color: '#dc2626', padding: 20, borderRadius: 8, textAlign: 'center' }}>
            {error}
          </div>
        )}

        {ist && !loading && (
          <div className="ist-receipt-page" style={{
            maxWidth: 700, margin: '0 auto', background: '#fff',
            padding: '36px 40px', boxShadow: '0 2px 12px rgba(0,0,0,0.08)',
            fontFamily: 'Arial, sans-serif', fontSize: 12, color: '#111',
          }}>

            {/* Company Header — centered letterhead; address reflects the SOURCE location */}
            <div style={{ textAlign: 'center', borderBottom: '2px solid #111', paddingBottom: 10, marginBottom: 14 }}>
              <div style={{ fontWeight: 800, fontSize: 22, textTransform: 'uppercase', letterSpacing: 0.8 }}>
                LGM Global Industries, Inc.
              </div>
              {ist.from_location?.location_name && (
                <div style={{ fontSize: 11, color: '#333', marginTop: 3 }}>{ist.from_location.location_name}</div>
              )}
              {ist.from_location?.address && (
                <div style={{ fontSize: 11, color: '#333', marginTop: 1 }}>{ist.from_location.address}</div>
              )}
              <div style={{ fontSize: 10, color: '#666', marginTop: 1 }}>Vat Reg. TIN#: 771-347-425-000</div>
            </div>

            {/* Title + IST Number */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', margin: '10px 0 6px' }}>
              <span style={{ fontWeight: 700, fontSize: 13, textTransform: 'uppercase', letterSpacing: 0.8 }}>
                Interbranch Stock Transfer
              </span>
              <span style={{ fontSize: 11 }}>
                No. <strong style={{ fontSize: 13 }}>{ist.transfer_number}</strong>
              </span>
            </div>

            {/* From / To / Date */}
            <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 10 }}>
              <tbody>
                <tr>
                  <td style={{ fontWeight: 700, width: 55, paddingBottom: 3 }}>FROM:</td>
                  <td style={{ paddingLeft: 6, paddingBottom: 3 }}>{ist.from_location?.location_name || '—'}</td>
                  <td style={{ width: 20 }}></td>
                  <td style={{ fontWeight: 700, width: 45, paddingBottom: 3 }}>DATE:</td>
                  <td style={{ paddingLeft: 6, paddingBottom: 3 }}>{fmtDate(ist.transfer_date)}</td>
                </tr>
                <tr>
                  <td style={{ fontWeight: 700 }}>TO:</td>
                  <td style={{ paddingLeft: 6 }}>{ist.to_location?.location_name || '—'}</td>
                  <td></td>
                  <td style={{ fontWeight: 700 }}>MR #:</td>
                  <td style={{ paddingLeft: 6 }}>{ist.transfer_number}</td>
                </tr>
              </tbody>
            </table>

            <hr style={{ border: 'none', borderTop: '1px solid #bbb', margin: '8px 0' }} />

            {/* Products Table */}
            <table style={{ width: '100%', borderCollapse: 'collapse', margin: '10px 0' }}>
              <thead>
                <tr>
                  <th style={{ background: '#1e293b', color: '#fff', padding: '6px 8px', textAlign: 'center', fontSize: 10, letterSpacing: 0.4, textTransform: 'uppercase', border: '1px solid #1e293b', width: 80 }}>
                    QUANTITY
                  </th>
                  <th style={{ background: '#1e293b', color: '#fff', padding: '6px 8px', textAlign: 'left', fontSize: 10, letterSpacing: 0.4, textTransform: 'uppercase', border: '1px solid #1e293b' }}>
                    MODEL / PRODUCT
                  </th>
                  <th style={{ background: '#1e293b', color: '#fff', padding: '6px 8px', textAlign: 'left', fontSize: 10, letterSpacing: 0.4, textTransform: 'uppercase', border: '1px solid #1e293b' }}>
                    SERIAL NO.
                  </th>
                </tr>
              </thead>
              <tbody>
                {(ist.details || []).map((d, idx) => {
                  const name    = d.product?.product_name || `Product #${d.product_id}`;
                  const model   = d.product?.model_code || d.product?.applianceDetail?.model_code || null;
                  const label   = model ? `${name} — ${model}` : name;
                  const qty     = (d.quantity_received > 0 ? d.quantity_received : d.quantity_transferred) || 0;
                  const serials = Array.isArray(d.item_serials) ? d.item_serials : [];

                  return (
                    <tr key={d.transfer_detail_id} style={{ background: idx % 2 === 1 ? '#f8fafc' : '#fff' }}>
                      <td style={{ padding: '5px 8px', border: '1px solid #d1d5db', textAlign: 'center', fontWeight: 600 }}>
                        {qty}
                      </td>
                      <td style={{ padding: '5px 8px', border: '1px solid #d1d5db' }}>
                        {label}
                      </td>
                      <td style={{ padding: '5px 8px', border: '1px solid #d1d5db', fontSize: 10, color: '#374151' }}>
                        {serials.length === 0 ? '—' : serials.join(', ')}
                      </td>
                    </tr>
                  );
                })}
                {(!ist.details || ist.details.length === 0) && (
                  <tr>
                    <td colSpan={3} style={{ textAlign: 'center', padding: 14, color: '#94a3b8', border: '1px solid #d1d5db' }}>
                      No line items.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>

            <hr style={{ border: 'none', borderTop: '1px solid #bbb', margin: '8px 0' }} />

            {/* Signature block */}
            <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 14 }}>
              <tbody>
                <tr>
                  <td style={{ padding: '3px 0', width: '50%' }}>
                    <span style={{ fontWeight: 700, color: '#374151' }}>Prepared by:</span>&nbsp;{fullName(ist.prepared_by)}
                  </td>
                  <td style={{ padding: '3px 0' }}>
                    <span style={{ fontWeight: 700, color: '#374151' }}>Approved by:</span>&nbsp;{fullName(ist.approved_by)}
                  </td>
                </tr>
                <tr>
                  <td style={{ padding: '3px 0' }}>
                    <span style={{ fontWeight: 700, color: '#374151' }}>Received by:</span>&nbsp;{fullName(ist.received_by)}
                  </td>
                  <td></td>
                </tr>
              </tbody>
            </table>

            <div style={{ marginTop: 36 }}>
              <div style={{ borderBottom: '1px solid #374151', width: '60%', marginBottom: 4 }}></div>
              <div style={{ fontSize: 10, color: '#64748b' }}>Receiver's Signature over Printed Name</div>
            </div>

          </div>
        )}
      </div>

      {/* Print CSS */}
      <style>{`
        @media print {
          .no-print { display: none !important; }
          body { background: #fff !important; }
          .ist-receipt-page {
            box-shadow: none !important;
            margin: 0 !important;
            padding: 16px !important;
            max-width: 100% !important;
          }
        }
      `}</style>
    </>
  );
};

export default ISTReceiptPage;
