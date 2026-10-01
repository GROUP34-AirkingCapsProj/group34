import React, { useEffect, useState } from 'react';
import Modal from './Modal';
import { companySettingsAPI, openApiFile } from '../services/api';
import { toast } from '../utils/toast';
import { layoutAirconGroups, airconGroupLabel, airconUnpairedLabel } from '../utils/airconGroups';

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
  const poDetailById = {};
  const poDetailByProduct = {};
  (po.details || []).forEach((d) => {
    orderedByProduct[d.product_id] = (orderedByProduct[d.product_id] || 0) + Number(d.quantity_ordered || 0);
    if (d.po_detail_id != null) poDetailById[d.po_detail_id] = d;
    if (!poDetailByProduct[d.product_id]) poDetailByProduct[d.product_id] = d;
  });
  const costFor = (detail) => {
    const poLine = poDetailById[detail.po_detail_id] || poDetailByProduct[detail.product_id];
    const piecesPerOrderedUnit = detail.product?.product_type === 'appliance'
      ? 1
      : Math.max(1, Number(detail.product?.pieces_per_package || 1));
    return poLine
      ? Number(poLine.unit_price || 0) / piecesPerOrderedUnit
      : Number(detail.product?.cost_price || 0);
  };
  const receivedCostTotal = (receiving.details || []).reduce(
    (sum, detail) => sum + costFor(detail) * Number(detail.quantity_amount || 0),
    0,
  );
  const money = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  // Split-aircon rows sit under one model header; an untagged receiving comes
  // back with aircon_groups: [] and lays out exactly as it always did.
  const detailSegments = layoutAirconGroups(
    receiving.details || [],
    receiving.aircon_groups,
    'receiving_detail_id',
  );

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
      + '<style>body{font-family:"Times New Roman",serif;color:#111;padding:24px}'
      + 'table{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px}'
      + 'th{border-top:2px solid #111;border-bottom:2px solid #111;padding:4px;text-align:left}'
      + 'td{border-bottom:1px solid #bbb;padding:4px}</style></head><body>'
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
        <p style={{ textAlign: 'center', margin: '2px 0 12px', color: 'var(--ink-slate-700-2)' }}>
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
            <tr>
              <th style={{ padding: 4, textAlign: 'left' }}>Product / Code</th>
              <th style={{ padding: 6 }}>Ordered</th>
              <th style={{ padding: 6 }}>Received</th>
              <th style={{ padding: 6, textAlign: 'right' }}>Unit Cost</th>
              <th style={{ padding: 6, textAlign: 'right' }}>Amount</th>
              <th style={{ padding: 6 }}>Condition</th>
            </tr>
          </thead>
          <tbody>
            {detailSegments.map((seg) => (
              <React.Fragment key={seg.key}>
                {seg.group && (
                  <tr style={{ background: 'var(--bg-hover)' }}>
                    <td colSpan={6} style={{ padding: 6, fontSize: 12, fontWeight: 700, color: 'var(--text-body)' }}>
                      {airconGroupLabel(seg.group)}
                      {seg.group.is_unbalanced && airconUnpairedLabel(seg.group.unpaired) && (
                        <span style={{ marginLeft: 8, fontWeight: 600, color: 'var(--ink-amber)' }}>
                          ⚠ {airconUnpairedLabel(seg.group.unpaired)}
                        </span>
                      )}
                    </td>
                  </tr>
                )}
                {seg.rows.map((d, i) => (
                  <tr key={d.receiving_detail_id ?? `${seg.key}-${i}`} style={{ borderBottom: '1px solid var(--border-color)' }}>
                    <td style={{ padding: 6, paddingLeft: seg.group ? 18 : 6 }}>
                      <strong>{d.product?.product_name || `Product #${d.product_id}`}</strong>
                      {d.product?.product_code ? ` · ${d.product.product_code}` : ''}
                    </td>
                    <td style={{ padding: 6, textAlign: 'center' }}>{orderedByProduct[d.product_id] ?? '—'}</td>
                    <td style={{ padding: 6, textAlign: 'center' }}>{Number(d.quantity_amount || 0)}</td>
                    <td style={{ padding: 6, textAlign: 'right' }}>{money(costFor(d))}</td>
                    <td style={{ padding: 6, textAlign: 'right' }}>{money(costFor(d) * Number(d.quantity_amount || 0))}</td>
                    <td style={{ padding: 6, textAlign: 'center' }}>{d.condition || 'Good'}</td>
                  </tr>
                ))}
              </React.Fragment>
            ))}
          </tbody>
        </table>
        <p style={{ textAlign: 'right', marginTop: 8, fontSize: 13 }}>
          <strong>Total received:</strong> {Number(receiving.total_quantity_received || 0)} pcs
          &nbsp;&nbsp; <strong>Received cost:</strong> {money(receivedCostTotal)}
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
