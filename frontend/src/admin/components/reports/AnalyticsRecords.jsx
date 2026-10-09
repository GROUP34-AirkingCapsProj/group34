import React, { useState } from 'react';
import Pagination from '../Pagination';

const PAGE_SIZE = 10;
const csvCell = value => {
  let text = String(value ?? '');
  if (typeof value === 'string' && /^\s*[=+\-@]/.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
};

export function analyticsCsv(columns, rows, context) {
  return '\uFEFF' + [
    columns.map(column => csvCell(column.label)).join(','),
    ...rows.map(row => columns.map(column => csvCell(row[column.key])).join(',')),
    '', ...Object.entries(context).map(([key, value]) => [key, value].map(csvCell).join(',')),
  ].join('\r\n');
}

export default function AnalyticsRecords({ title, columns, rows = [], context = {}, filter }) {
  const [page, setPage] = useState(1);
  const pages = Math.ceil(rows.length / PAGE_SIZE) || 1;
  const current = Math.min(page, pages);
  const visible = rows.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);
  const exportCsv = () => {
    const blob = new Blob([analyticsCsv(columns, rows, context)], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
  return (
    <section className="analytics-card analytics-records" aria-label={title}>
      <div className="analytics-records-header">
        <div><h3>{title}</h3><p className="card-subtitle">All {rows.length} supporting records. Pagination changes the displayed rows; summaries cover all matching records.</p></div>
        <button type="button" className="ak-btn ak-btn--secondary" onClick={exportCsv} disabled={!rows.length}>Export these records (CSV)</button>
      </div>
      <p className="card-subtitle">{Object.entries(context).map(([key, value]) => `${key}: ${value}`).join(' · ')}</p>
      {filter}
      <div className="consumption-table-wrapper">
        <table className="consumption-table">
          <thead><tr>{columns.map(column => <th key={column.key} scope="col">{column.label}</th>)}</tr></thead>
          <tbody>
            {!visible.length && <tr><td colSpan={columns.length}>No matching records.</td></tr>}
            {visible.map((row, index) => <tr key={row.id ?? row.productId ?? index}>{columns.map(column => (
              <td key={column.key}>{column.money
                ? Number(row[column.key] ?? 0).toLocaleString('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: column.key === 'cost_price' ? 4 : 2 })
                : String(row[column.key] ?? '—')}</td>
            ))}</tr>)}
          </tbody>
        </table>
      </div>
      <Pagination currentPage={current} totalPages={pages} onPageChange={setPage} totalItems={rows.length} pageSize={PAGE_SIZE} label="records" />
    </section>
  );
}
