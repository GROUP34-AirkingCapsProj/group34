import React, { useEffect, useState } from 'react';
import Modal from './Modal';
import { companySettingsAPI, openApiFile } from '../services/api';
import { toast } from '../utils/toast';

/**
 * On-screen Goods Received Note for one receiving session, with Print + download
 * actions. `receiving` should include receiving_number, receiving_date,
 * total_quantity_received, purchaseOrder (po_number, supplier, details), location,
 * receivedBy, details[]. Missing nested fields degrade gracefully — the
 * authoritative document is the downloadable PDF.
 */
export default function ReceivingReceipt({ receiving, isOpen, onClose }) {
  const [paperSize, setPaperSize] = useState('a4');
  const [companySettings, setCompanySettings] = useState({});

  useEffect(() => {
    companySettingsAPI.get()
      .then((settings) => {
        setCompanySettings(settings || {});
        setPaperSize(settings?.pdf_paper_size || 'a4');
      })
      .catch(() => {});
  }, []);

  if (!receiving) return null;

  const po = receiving.purchaseOrder || {};
  const orderedByProduct = {};
  (po.details || []).forEach((d) => {
    orderedByProduct[d.product_id] = (orderedByProduct[d.product_id] || 0) + Number(d.quantity_ordered || 0);
  });

  const receiverName =
    receiving.received_by_name
    || `${receiving.receivedBy?.first_name || ''} ${receiving.receivedBy?.last_name || ''}`.trim()
    || '—';

  const printReceipt = () => {
    const node = document.getElementById('grn-print-area');
    if (!node) return;
    const w = window.open('', '_blank', 'width=800,height=900');
    if (!w) return;
    w.document.write(
      `<html><head><title>GRN-${receiving.receiving_number}</title>`
      + '<style>body{font-family:Arial,sans-serif;color:#111;padding:24px}'
      + 'table{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px}'
      + 'th{background:#1e293b;color:#fff;padding:6px;text-align:left}'
      + 'td{border-bottom:1px solid #e2e8f0;padding:6px}</style></head><body>'
      + node.innerHTML + '</body></html>'
    );
    w.document.close();
    w.focus();
    w.print();
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Goods Received Note" maxWidth={640}>
      <div id="grn-print-area" className="grn-doc">
        <h2 style={{ textAlign: 'center', margin: 0 }}>{companySettings.company_name || 'Airking Air Conditioning'}</h2>
        <p style={{ textAlign: 'center', margin: '2px 0 12px', color: '#555' }}>
          {[companySettings.company_address, companySettings.company_email, companySettings.company_phone].filter(Boolean).join(' · ')}
        </p>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13 }}>
          <div>
            <div><strong>GRN #:</strong> {receiving.receiving_number}</div>
            <div><strong>PO #:</strong> {po.po_number || '—'}</div>
          </div>
          <div>
            <div><strong>Received:</strong> {receiving.receiving_date ? String(receiving.receiving_date).slice(0, 10) : '—'}</div>
            <div><strong>Supplier:</strong> {receiving.supplier?.supplier_name || po.supplier?.supplier_name || '—'}</div>
          </div>
        </div>
        <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 12, fontSize: 13 }}>
          <thead>
            <tr style={{ background: '#1e293b', color: '#fff' }}>
              <th style={{ padding: 6, textAlign: 'left' }}>Product</th>
              <th style={{ padding: 6 }}>Ordered</th>
              <th style={{ padding: 6 }}>Received</th>
              <th style={{ padding: 6 }}>Condition</th>
            </tr>
          </thead>
          <tbody>
            {(receiving.details || []).map((d, i) => (
              <tr key={i} style={{ borderBottom: '1px solid #e2e8f0' }}>
                <td style={{ padding: 6 }}>{d.product?.product_name || `Product #${d.product_id}`}</td>
                <td style={{ padding: 6, textAlign: 'center' }}>{orderedByProduct[d.product_id] ?? '—'}</td>
                <td style={{ padding: 6, textAlign: 'center' }}>{Number(d.quantity_amount || 0)}</td>
                <td style={{ padding: 6, textAlign: 'center' }}>{d.condition || 'Good'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <p style={{ textAlign: 'right', marginTop: 8, fontSize: 13 }}>
          <strong>Total received:</strong> {Number(receiving.total_quantity_received || 0)} pcs
        </p>
        <p style={{ marginTop: 24, fontSize: 13 }}>
          <strong>Received by:</strong> {receiverName}
        </p>
      </div>

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
        <select className="po-input" aria-label="PDF paper size" value={paperSize} onChange={(e) => setPaperSize(e.target.value)} style={{ width: 105 }}>
          <option value="a4">A4</option>
          <option value="letter">Letter</option>
          <option value="legal">Legal</option>
          <option value="a5">A5</option>
        </select>
        <button type="button" className="ak-btn ak-btn--ghost" onClick={printReceipt}>Print</button>
        <button type="button" className="ak-btn ak-btn--primary" onClick={() => openApiFile(`/receivings/${receiving.receiving_id}/receipt/pdf?paper_size=${paperSize}`, { download: true, filename: `GRN-${receiving.receiving_number}.pdf` }).catch((e) => toast.error(e.message))}>Download PDF</button>
      </div>
    </Modal>
  );
}
