import React, { useCallback, useEffect, useState } from 'react';
import { labelPrintersAPI } from '../services/api';
import { toast } from '../utils/toast';

/**
 * Which printer each location uses.
 *
 * Every active location is listed, including those with no printer, because
 * the panel's job is to show which warehouses still need one — a printer
 * bought next month is set up here rather than in a file on the server.
 *
 * The label stock is shown but not editable. The sticker layout is built
 * around those numbers in the frontend, so an admin able to change one but
 * not the other would make the PDF and the printer disagree about what a
 * sticker is.
 */

const TRANSPORTS = [
  { value: '', label: 'Not set up' },
  { value: 'tcp', label: 'Network (IP address)' },
  { value: 'share', label: 'Windows share or port' },
];

const describe = (printer) => {
  if (printer?.transport === 'tcp') return `Network · ${printer.host}:${printer.port}`;
  if (printer?.transport === 'share') return `Share · ${printer.share}`;
  return 'Not set up';
};

export default function LabelPrinterPanel() {
  const [rows, setRows] = useState([]);
  const [label, setLabel] = useState(null);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);   // location_id being edited
  const [form, setForm] = useState(null);
  const [saving, setSaving] = useState(false);
  const [results, setResults] = useState({});     // location_id → { ok, message }

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await labelPrintersAPI.getAll();
      setRows(res?.data?.locations || []);
      setLabel(res?.data?.label || null);
    } catch (e) {
      toast.error(e?.message || 'Could not load the printer settings.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const startEditing = (row) => {
    setEditing(row.location_id);
    setForm({ ...row.printer });
  };

  const cancelEditing = () => {
    setEditing(null);
    setForm(null);
  };

  const save = async () => {
    setSaving(true);
    try {
      await labelPrintersAPI.update(editing, form);
      toast.success('Printer settings saved.');
      cancelEditing();
      await load();
    } catch (e) {
      toast.error(e?.message || 'Could not save the printer settings.');
    } finally {
      setSaving(false);
    }
  };

  /* Kept beside the row rather than raised as a toast: the message names what
     to go and check — a port, a network, a share permission — and a clerk
     setting a printer up needs to read it while looking at the settings. */
  const runTest = async (locationId) => {
    try {
      const res = await labelPrintersAPI.test(locationId);
      setResults((current) => ({ ...current, [locationId]: res?.data }));
    } catch (e) {
      setResults((current) => ({
        ...current,
        [locationId]: { ok: false, message: e?.message || 'The test could not be run.' },
      }));
    }
  };

  return (
    <div className="sc-card">
      <div className="sc-card-header">
        <h3>Barcode printers</h3>
      </div>

      <div className="sc-card-body">
        {loading && <p className="lpp-status">Loading printers…</p>}

        {!loading && rows.length === 0 && (
          <p className="lpp-status">No active locations exist yet.</p>
        )}

        {rows.map((row) => (
          <div className="lpp-row" key={row.location_id}>
            <div className="lpp-row-head">
              <span className="lpp-location">{row.location_name}</span>
              <span className="lpp-detail">{describe(row.printer)}</span>

              <div className="lpp-actions">
                <button type="button" onClick={() => startEditing(row)}>
                  {row.configured ? 'Edit' : 'Set up'}
                </button>
                {/* Only where there is something to reach. A test button on an
                    unconfigured location could only ever report failure. */}
                {row.configured && (
                  <button type="button" onClick={() => runTest(row.location_id)}>
                    Test
                  </button>
                )}
              </div>
            </div>

            {results[row.location_id] && (
              <p
                className={`lpp-result${results[row.location_id].ok ? ' lpp-result--ok' : ''}`}
                role="status"
              >
                {results[row.location_id].message}
              </p>
            )}

            {editing === row.location_id && form && (
              <div className="lpp-form">
                <label htmlFor={`lpp-transport-${row.location_id}`}>Connection</label>
                <select
                  id={`lpp-transport-${row.location_id}`}
                  value={form.transport || ''}
                  onChange={(e) => setForm({ ...form, transport: e.target.value })}
                >
                  {TRANSPORTS.map((t) => (
                    <option key={t.value} value={t.value}>{t.label}</option>
                  ))}
                </select>

                {form.transport === 'tcp' && (
                  <>
                    <label htmlFor={`lpp-host-${row.location_id}`}>Address</label>
                    <input
                      id={`lpp-host-${row.location_id}`}
                      type="text"
                      value={form.host || ''}
                      placeholder="192.168.1.50"
                      onChange={(e) => setForm({ ...form, host: e.target.value })}
                    />

                    <label htmlFor={`lpp-port-${row.location_id}`}>Port</label>
                    <input
                      id={`lpp-port-${row.location_id}`}
                      type="number"
                      value={form.port ?? 9100}
                      onChange={(e) => setForm({ ...form, port: Number(e.target.value) })}
                    />
                  </>
                )}

                {form.transport === 'share' && (
                  <>
                    <label htmlFor={`lpp-share-${row.location_id}`}>Share or port</label>
                    <input
                      id={`lpp-share-${row.location_id}`}
                      type="text"
                      value={form.share || ''}
                      placeholder="\\THIS-PC\Zebra"
                      onChange={(e) => setForm({ ...form, share: e.target.value })}
                    />
                  </>
                )}

                <div className="lpp-form-actions">
                  <button type="button" onClick={cancelEditing}>Cancel</button>
                  <button type="button" disabled={saving} onClick={save}>
                    {saving ? 'Saving…' : 'Save'}
                  </button>
                </div>
              </div>
            )}
          </div>
        ))}

        {label && (
          <p className="lpp-stock">
            Label stock <strong>{label.width_mm} × {label.height_mm} mm</strong> at {label.dpi} dpi.
            Order this size — the sticker layout is built around it, so changing it needs a developer.
          </p>
        )}
      </div>
    </div>
  );
}
