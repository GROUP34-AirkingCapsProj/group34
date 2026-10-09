import React, { useEffect, useMemo, useState } from 'react';
import Modal from '../Modal';

/**
 * Mirrors the `type` strings App\Reports\ReportParameter::toArray() sends —
 * kept as plain constants (not a shared module) since this is the only
 * frontend file that switches on them.
 */
const PARAM_TYPE = {
  DATE_RANGE: 'date_range',
  LOCATION: 'location',
  LOCATION_TYPE: 'location_type',
  CATEGORY: 'category',
  USER: 'user',
  SELECT: 'select',
};

/**
 * The fixed set of location types every location row can be — not a
 * report-specific list, so hardcoding it here is not the bug this project
 * exists to fix (a report advertising a filter it doesn't honour). The
 * `location_type` parameter type itself IS this enum; the backend never sends
 * options for it (see ReportParameter::rules()), so there is nothing to read
 * off the catalog. Mirrors LocationManagement.jsx's LOCATION_TYPES.
 */
const LOCATION_TYPES = [
  { value: 'warehouse', label: 'Warehouse' },
  { value: 'showroom', label: 'Showroom' },
  { value: 'branch_office', label: 'Branch Office' },
];

const FORMAT_LABELS = { pdf: 'PDF', csv: 'CSV' };

/** date_range is one control but two wire fields — see ReportParameter::requestKeys(). */
const isDateRange = (type) => type === PARAM_TYPE.DATE_RANGE;

/**
 * Modal that builds its inputs entirely from `report.parameters`. A control
 * only appears here because the report declared it — never a hardcoded
 * per-report switch — so the dialog can never offer a filter the backend
 * silently ignores.
 *
 * Props:
 *   report         {object}   catalog entry: {key, title, description, formats, parameters}
 *   locations      {array}    for the `location` parameter type
 *   categories     {array}    for the `category` parameter type
 *   users          {array}    for the `user` parameter type
 *   initialValues  {{format, parameters}=}  seeds the form when re-running an expired run
 *   generating     {boolean}  disables the submit button while a request is in flight
 *   onGenerate     {(payload: {reportKey, format, parameters}) => void}
 *   onClose        {() => void}
 */
export default function GenerateReportDialog({
  report,
  locations = [],
  categories = [],
  users = [],
  initialValues = null,
  generating = false,
  onGenerate,
  onClose,
}) {
  const parameters = useMemo(() => (Array.isArray(report?.parameters) ? report.parameters : []), [report]);
  const formats = useMemo(() => (Array.isArray(report?.formats) ? report.formats : []), [report]);

  const [values, setValues] = useState({});
  const [format, setFormat] = useState('');
  const [error, setError] = useState('');

  // Seed on open / when the target report changes — defaults from the catalog,
  // overridden by initialValues when re-running an expired run.
  useEffect(() => {
    const seeded = {};
    parameters.forEach((param) => {
      if (isDateRange(param.type)) {
        seeded.date_from = initialValues?.parameters?.date_from ?? param.default?.date_from ?? '';
        seeded.date_to = initialValues?.parameters?.date_to ?? param.default?.date_to ?? '';
      } else {
        seeded[param.key] = initialValues?.parameters?.[param.key] ?? param.default ?? '';
      }
    });
    setValues(seeded);
    setFormat(initialValues?.format ?? formats[0] ?? '');
    setError('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [report?.key]);

  if (!report) return null;

  const setField = (key, value) => setValues((prev) => ({ ...prev, [key]: value }));

  const handleSubmit = (e) => {
    e.preventDefault();
    setError('');

    for (const param of parameters) {
      if (isDateRange(param.type)) {
        const from = values.date_from;
        const to = values.date_to;
        if (param.required && (!from || !to)) {
          setError(`${param.label} is required.`);
          return;
        }
        if (from && to && from > to) {
          setError('Start date must be on or before the end date.');
          return;
        }
      } else if (param.required && (values[param.key] === '' || values[param.key] == null)) {
        setError(`${param.label} is required.`);
        return;
      }
    }

    // Omit empty values rather than send blanks the backend would validate
    // as present-but-invalid.
    const wireParameters = {};
    parameters.forEach((param) => {
      if (isDateRange(param.type)) {
        if (values.date_from) wireParameters.date_from = values.date_from;
        if (values.date_to) wireParameters.date_to = values.date_to;
      } else if (values[param.key] !== '' && values[param.key] != null) {
        wireParameters[param.key] = values[param.key];
      }
    });

    onGenerate?.({ reportKey: report.key, format, parameters: wireParameters });
  };

  return (
    <Modal isOpen onClose={onClose} title={`Generate: ${report.title}`} maxWidth={520}>
      <form onSubmit={handleSubmit}>
        {report.description && (
          <p className="ak-muted" style={{ marginTop: 0 }}>{report.description}</p>
        )}
        <p className="ak-muted">{format === 'pdf' ? 'PDF includes graphs and detailed records from the same filtered dataset.' : 'CSV contains the report data for spreadsheets.'}</p>

        {parameters.map((param) => (
          <ParameterField
            key={param.key}
            param={param}
            values={values}
            setField={setField}
            locations={locations}
            categories={categories}
            users={users}
          />
        ))}

        {formats.length > 0 && (
          <fieldset className="ak-field" style={{ border: 'none', padding: 0, margin: '0 0 0.9rem' }}>
            <legend className="ak-label" style={{ padding: 0 }}>Format</legend>
            <div style={{ display: 'flex', gap: '1rem' }}>
              {formats.map((f) => (
                <label key={f} style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontWeight: 400 }}>
                  <input
                    type="radio"
                    name="report-format"
                    value={f}
                    checked={format === f}
                    onChange={() => setFormat(f)}
                  />
                  {FORMAT_LABELS[f] || f.toUpperCase()}
                </label>
              ))}
            </div>
          </fieldset>
        )}

        {error && (
          <div className="ak-muted" role="alert" style={{ color: 'var(--text-danger)', marginBottom: '0.9rem' }}>
            {error}
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.6rem' }}>
          <button type="button" className="ak-btn ak-btn--secondary" onClick={onClose} disabled={generating}>
            Cancel
          </button>
          <button type="submit" className="ak-btn ak-btn--primary" disabled={generating}>
            {generating ? 'Generating…' : 'Generate'}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function ParameterField({ param, values, setField, locations, categories, users }) {
  const fieldId = `gen-report-${param.key}`;

  if (isDateRange(param.type)) {
    return (
      <div className="ak-field" style={{ display: 'flex', gap: '0.75rem' }}>
        <div style={{ flex: 1 }}>
          <label className="ak-label" htmlFor={`${fieldId}-from`}>
            {param.label} — From{param.required ? ' *' : ''}
          </label>
          <input
            id={`${fieldId}-from`}
            className="ak-input"
            type="date"
            value={values.date_from || ''}
            onChange={(e) => setField('date_from', e.target.value)}
          />
        </div>
        <div style={{ flex: 1 }}>
          <label className="ak-label" htmlFor={`${fieldId}-to`}>
            {param.label} — To{param.required ? ' *' : ''}
          </label>
          <input
            id={`${fieldId}-to`}
            className="ak-input"
            type="date"
            value={values.date_to || ''}
            onChange={(e) => setField('date_to', e.target.value)}
          />
        </div>
      </div>
    );
  }

  if (param.type === PARAM_TYPE.LOCATION) {
    return (
      <SelectField
        fieldId={fieldId}
        param={param}
        value={values[param.key]}
        onChange={(v) => setField(param.key, v)}
        options={locations.map((loc) => ({
          value: String(loc.location_id ?? loc.id ?? ''),
          label: loc.location_name || loc.name || `Location #${loc.location_id ?? loc.id}`,
        }))}
        allLabel="All locations"
      />
    );
  }

  if (param.type === PARAM_TYPE.LOCATION_TYPE) {
    return (
      <SelectField
        fieldId={fieldId}
        param={param}
        value={values[param.key]}
        onChange={(v) => setField(param.key, v)}
        options={LOCATION_TYPES}
        allLabel="All location types"
      />
    );
  }

  if (param.type === PARAM_TYPE.CATEGORY) {
    return (
      <SelectField
        fieldId={fieldId}
        param={param}
        value={values[param.key]}
        onChange={(v) => setField(param.key, v)}
        options={categories.map((cat) => ({
          value: String(cat.category_id ?? cat.id ?? ''),
          label: cat.category_name || cat.name || `Category #${cat.category_id ?? cat.id}`,
        }))}
        allLabel="All categories"
      />
    );
  }

  if (param.type === PARAM_TYPE.USER) {
    return (
      <SelectField
        fieldId={fieldId}
        param={param}
        value={values[param.key]}
        onChange={(v) => setField(param.key, v)}
        options={users.map((u) => ({
          value: String(u.user_id ?? u.id ?? ''),
          label: [u.first_name, u.last_name].filter(Boolean).join(' ').trim()
            || u.username || u.email || `User #${u.user_id ?? u.id}`,
        }))}
        allLabel="All users"
      />
    );
  }

  if (param.type === PARAM_TYPE.SELECT) {
    const options = Object.entries(param.options || {}).map(([value, label]) => ({ value, label }));
    return (
      <SelectField
        fieldId={fieldId}
        param={param}
        value={values[param.key]}
        onChange={(v) => setField(param.key, v)}
        options={options}
        allLabel={`Any ${param.label.toLowerCase()}`}
      />
    );
  }

  // An unknown parameter type is a contract break between frontend and
  // backend — surface it rather than silently drop the filter.
  return (
    <div className="ak-muted" style={{ marginBottom: '0.9rem' }}>
      Unsupported filter type "{param.type}" for {param.label}.
    </div>
  );
}

function SelectField({ fieldId, param, value, onChange, options, allLabel }) {
  // The "All …" entry is the empty value, and handleSubmit drops empty values
  // from the payload — so it only means "all" for a filter the backend widens
  // when the key is absent. That is false twice over: a required parameter is
  // rejected outright, and a parameter with a default has that default put
  // back by ReportRunner::validateAndApplyDefaults(). Offering it there would
  // advertise a filter the backend does not honour, which is the one thing
  // this dialog exists to prevent.
  const canBeCleared = !param.required && param.default == null;

  return (
    <div className="ak-field">
      <label className="ak-label" htmlFor={fieldId}>
        {param.label}{param.required ? ' *' : ''}
      </label>
      <select
        id={fieldId}
        className="ak-input"
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
      >
        {allLabel && canBeCleared && <option value="">{allLabel}</option>}
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>{opt.label}</option>
        ))}
      </select>
    </div>
  );
}
