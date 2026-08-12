import React from 'react';
import DocumentSection, { DocField } from './DocumentSection';
import CameraCapture from '../../CameraCapture';
import ReportPhoto from '../ReportPhoto';

/**
 * The client's "Problem summary": whose unit it is, where it is sitting, and
 * what actually happened.
 *
 * The customer block is shown only for the two types that involve a customer at
 * all. A picker filing a theft must never be asked for a customer's site
 * address; there isn't one, and asking teaches people the form is full of
 * fields to ignore.
 *
 * The narrative is the one thing every type asks for, and the one thing that is
 * required — a report nobody described is a row in a table, not a report.
 *
 * `photo` is the whole useIncidentPhoto hook value, owned by the document so
 * the chosen file survives a re-render. Calling `photo.clear` directly is safe
 * here: this component has no hooks, so the member expression exhaustive-deps
 * cannot follow is not a problem.
 */
export default function ProblemSummarySection({
  state, form, patch, showCustomer, photo, cameraOpen, setCameraOpen, disabled, report,
}) {
  const readOnly = state !== 'editable';
  const locked = disabled || readOnly;
  const set = (key) => (v) => patch({ [key]: v });

  return (
    <DocumentSection id="incdoc-problem" title="Problem summary" state={state}>
      {showCustomer && (
        <>
          <div className="incdoc__row">
            <DocField id="incdoc-purchased-at" label="Date of purchase" type="date"
              value={form.purchasedAt} onChange={set('purchasedAt')} disabled={locked} />
            <DocField id="incdoc-customer-name" label="Customer name"
              value={form.customerName} onChange={set('customerName')} disabled={locked} />
            <DocField id="incdoc-customer-contact" label="Contact number" maxLength={40}
              value={form.customerContact} onChange={set('customerContact')} disabled={locked} />
          </div>
          <DocField id="incdoc-site-address" label="Site address" textarea maxLength={255}
            value={form.siteAddress} onChange={set('siteAddress')} disabled={locked} />
        </>
      )}

      <DocField
        id="incdoc-narrative"
        label="What happened? *"
        textarea
        rows={4}
        maxLength={2000}
        value={form.narrative}
        onChange={set('narrative')}
        placeholder="What happened, where, and anything a reviewer would need to know…"
        disabled={locked}
        hint={`${String(form.narrative || '').length}/2000`}
      />

      {/* Locked, not dropped. The reporter's photo is part of the claim the
          inspector is checking and the admin is approving. */}
      {readOnly && report && (
        <ReportPhoto
          incidentId={report.incident_id}
          photoPath={report.photo_path}
          photoSource={report.photo_source}
        />
      )}

      {!readOnly && (
        <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
          <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>
            Photo (optional)
          </legend>
          <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
            One quick photo for context. The full evidence checklist below is the
            inspector&apos;s — you are not expected to complete it.
          </span>

          {!photo.file && !cameraOpen && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap', marginTop: '0.4rem' }}>
              <button
                type="button"
                className="ak-btn ak-btn--primary"
                onClick={() => setCameraOpen(true)}
                disabled={disabled}
              >
                📷 Take photo
              </button>
              <span className="ak-muted" style={{ fontSize: '0.85rem' }}>or</span>
              <input
                id="incdoc-photo"
                className="ak-input"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e) => photo.setFromFile(e.target.files?.[0] || null)}
                disabled={disabled}
                style={{ maxWidth: 260 }}
              />
            </div>
          )}

          <CameraCapture
            isOpen={cameraOpen}
            onCapture={(f) => { photo.setFromCamera(f); setCameraOpen(false); }}
            onCancel={() => setCameraOpen(false)}
          />

          {photo.file && (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', marginTop: '0.4rem' }}>
              {photo.previewUrl && (
                <img src={photo.previewUrl} alt="Evidence preview"
                     style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 6 }} />
              )}
              {/* Never "verified": a capture and a gallery pick arrive as
                  identical uploads, so this states what the app observed. */}
              <span className={`ak-badge ${photo.source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
                {photo.source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
              </span>
              <button type="button" className="ak-btn ak-btn--sm" onClick={photo.clear} disabled={disabled}>
                Remove
              </button>
            </div>
          )}
        </fieldset>
      )}
    </DocumentSection>
  );
}
