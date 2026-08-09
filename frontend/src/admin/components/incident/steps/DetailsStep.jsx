import React from 'react';
import CameraCapture from '../../CameraCapture';
import { reasonLabel } from '../../../utils/incidentReasons';

/**
 * Final step — the narrative and the optional evidence photo, above a read-only
 * recap of the earlier steps so the filer can check their answers without
 * stepping back through them.
 *
 * `photo` is the whole useIncidentPhoto hook value, owned by IncidentFlow so the
 * chosen file survives a Back/Next round trip. Calling `photo.clear` directly is
 * safe here — this component has no hooks, so the member expression that
 * exhaustive-deps could not follow in IncidentFileModal is not a problem.
 */
export default function DetailsStep({
  reason, occurredAt, locationName, productName, totalPieces, showQuantity,
  narrative, setNarrative, photo, cameraOpen, setCameraOpen, disabled,
}) {
  return (
    <>
      <div className="ak-card" style={{ padding: '0.75rem', marginBottom: '0.75rem' }}>
        <div className="ak-muted" style={{ fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
          Reporting
        </div>
        <div style={{ fontSize: '0.9rem', marginTop: '0.25rem' }}>
          <strong>{reasonLabel(reason)}</strong>
          {locationName ? ` at ${locationName}` : ''}
          {productName ? ` · ${productName}` : ''}
          {showQuantity ? ` · ${totalPieces} pcs` : ''}
          {occurredAt ? ` · ${new Date(occurredAt).toLocaleString()}` : ''}
        </div>
      </div>

      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-narrative">Describe what you saw *</label>
        <textarea
          id="incident-flow-narrative"
          className="ak-input"
          rows={4}
          maxLength={2000}
          value={narrative}
          onChange={(e) => setNarrative(e.target.value)}
          disabled={disabled}
          placeholder="What happened, where, and anything a reviewer would need to know…"
        />
        <span className="ak-muted" style={{ fontSize: '0.75rem' }}>{narrative.length}/2000</span>
      </div>

      <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
        <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>Evidence photo (optional)</legend>

        {!photo.file && !cameraOpen && (
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
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
              id="incident-flow-photo"
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
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {photo.previewUrl && (
              <img src={photo.previewUrl} alt="Evidence preview"
                   style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 6 }} />
            )}
            <span className={`ak-badge ${photo.source === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
              {photo.source === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
            </span>
            <button
              type="button"
              className="ak-btn ak-btn--sm"
              onClick={photo.clear}
              disabled={disabled}
            >
              Remove
            </button>
          </div>
        )}
      </fieldset>
    </>
  );
}
