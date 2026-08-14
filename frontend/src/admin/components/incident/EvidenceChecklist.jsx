import React, { useCallback, useState } from 'react';
import CameraCapture from '../CameraCapture';
import { useIncidentPhoto } from '../../hooks/useIncidentPhoto';
import { incidentReportsAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { requirementMeta, requirementsFor } from '../../utils/incidentDocument';
import { EvidenceThumbnail, PhotoSourceBadge } from './EvidencePhoto';

/**
 * The matrix, the labels and both derived lists live in
 * `utils/incidentDocument`, so the document can show a reporter in `file` mode
 * what the inspector will be asked for and block submission on the same list
 * the checklist renders — one list, not three that can disagree. They mirror
 * App\Models\IncidentEvidence::requiredFor() and ::REQUIREMENTS, which are the
 * authority; the client copy exists so an inspector is told what is outstanding
 * before they submit rather than after a 422.
 *
 * EVIDENCE_REQUIREMENTS and requirementMeta were defined here once. They moved
 * because the admin's READ-ONLY summary needs the labels, and importing them
 * from this file would have pulled CameraCapture, useIncidentPhoto, `toast` and
 * the mutating API calls below into a view that must not be able to write.
 * Import them from the util; this file deliberately re-exports nothing, so the
 * barrier cannot be crossed by accident.
 */

/**
 * Props:
 *   incidentId  {number}
 *   reason      {string}
 *   checkedByAsc {boolean}
 *   returnReasonCode {string} the checklist is reason-driven; a return without
 *                             it falls back to the sellability list

 *   evidence    {array}    rows already attached, from GET /incident-reports/{id}
 *   onAdded     {(row) => void}
 *   onRemoved   {(evidenceId) => void}
 *   disabled    {boolean}
 */
export default function EvidenceChecklist({
  incidentId, reason, checkedByAsc, returnReasonCode, evidence, onAdded, onRemoved, disabled,
}) {
  const required = requirementsFor(reason, checkedByAsc, returnReasonCode);
  const rows = Array.isArray(evidence) ? evidence : [];

  if (required.length === 0) {
    return (
      <div className="ak-state">
        This type of report asks for no photographs. Nothing is being held to
        photograph, so the checklist does not apply — describe what you found in
        the findings instead.
      </div>
    );
  }

  return (
    <div className="ak-field" style={{ gap: '0.8rem' }}>
      {required.map((req) => (
        <RequirementBlock
          key={req}
          incidentId={incidentId}
          requirement={req}
          attached={rows.filter((e) => e.requirement === req)}
          onAdded={onAdded}
          onRemoved={onRemoved}
          disabled={disabled}
        />
      ))}

      {/* Anything attached against a requirement this reason no longer asks for
          — e.g. an ASC report attached before the tick was cleared. Hiding it
          would look like the file had been deleted when it is still on the
          report and still shown to the admin. */}
      {rows.filter((e) => !required.includes(e.requirement)).map((e) => (
        <div key={e.evidence_id} className="ak-muted" style={{ fontSize: '0.82rem' }}>
          Also attached: {requirementMeta(e.requirement).label}
          {e.skipped_reason ? ` — skipped: ${e.skipped_reason}` : ''}
          <button
            type="button"
            className="ak-btn ak-btn--sm"
            style={{ marginLeft: '0.5rem' }}
            disabled={disabled}
            onClick={async () => {
              try {
                await incidentReportsAPI.removeEvidence(incidentId, e.evidence_id);
                onRemoved?.(e.evidence_id);
              } catch (err) {
                toast.error(err.message || 'Could not remove that attachment.');
              }
            }}
          >
            Remove
          </button>
        </div>
      ))}
    </div>
  );
}

/**
 * One checklist line: take a photo, upload one, or say in writing why neither
 * is possible.
 *
 * A skip is data, not an absence — it is what lets the admin see that the box
 * photo is missing BECAUSE the box was already discarded, rather than seeing a
 * thin report and guessing.
 */
function RequirementBlock({ incidentId, requirement, attached, onAdded, onRemoved, disabled }) {
  const meta = requirementMeta(requirement);
  const photo = useIncidentPhoto();
  const [cameraOpen, setCameraOpen] = useState(false);
  const [skipping, setSkipping] = useState(false);
  const [skipReason, setSkipReason] = useState('');
  const [busy, setBusy] = useState(false);

  const { uploadIfAny, clear } = photo;

  const attach = useCallback(async () => {
    setBusy(true);
    try {
      // uploadIfAny THROWS when an upload returns no path, rather than
      // recording an attachment that points at nothing.
      const { photo_path: photoPath, photo_source: photoSource } = await uploadIfAny();
      const res = await incidentReportsAPI.addEvidence(incidentId, {
        requirement,
        photo_path: photoPath,
        photo_source: photoSource,
      });
      onAdded?.(res?.data);
      clear();
    } catch (err) {
      toast.error(err.message || 'Could not attach that photo.');
    } finally {
      setBusy(false);
    }
  }, [uploadIfAny, clear, incidentId, requirement, onAdded]);

  const recordSkip = useCallback(async () => {
    const reason = skipReason.trim();
    if (!reason) return;
    setBusy(true);
    try {
      const res = await incidentReportsAPI.addEvidence(incidentId, {
        requirement,
        skipped_reason: reason,
      });
      onAdded?.(res?.data);
      setSkipping(false);
      setSkipReason('');
    } catch (err) {
      toast.error(err.message || 'Could not record that.');
    } finally {
      setBusy(false);
    }
  }, [skipReason, incidentId, requirement, onAdded]);

  const remove = useCallback(async (evidenceId) => {
    setBusy(true);
    try {
      await incidentReportsAPI.removeEvidence(incidentId, evidenceId);
      onRemoved?.(evidenceId);
    } catch (err) {
      toast.error(err.message || 'Could not remove that attachment.');
    } finally {
      setBusy(false);
    }
  }, [incidentId, onRemoved]);

  const satisfied = attached.length > 0;
  const skipped = satisfied && attached.every((e) => !e.photo_path);

  return (
    <fieldset
      className="ak-card"
      style={{ border: '1px solid var(--ak-line, #ddd)', padding: '0.75rem', margin: 0 }}
    >
      <legend className="ak-label" style={{ padding: '0 0.35rem' }}>
        {meta.label}{' '}
        {satisfied ? (
          <span className={`ak-badge ${skipped ? 'ak-badge--gray' : 'ak-badge--green'}`}>
            {skipped ? 'Skipped' : `${attached.filter((e) => e.photo_path).length} attached`}
          </span>
        ) : (
          <span className="ak-badge ak-badge--amber">Outstanding</span>
        )}
      </legend>

      <div className="ak-muted" style={{ fontSize: '0.82rem', marginBottom: '0.5rem' }}>{meta.help}</div>

      {attached.map((e) => (
        <div
          key={e.evidence_id}
          style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', marginBottom: '0.4rem' }}
        >
          {e.photo_path ? (
            <EvidenceThumbnail incidentId={incidentId} evidenceId={e.evidence_id} />
          ) : (
            <span style={{ fontSize: '0.85rem' }}>
              <strong>Skipped:</strong> {e.skipped_reason}
            </span>
          )}
          <PhotoSourceBadge source={e.photo_source} />
          <button
            type="button"
            className="ak-btn ak-btn--sm"
            onClick={() => remove(e.evidence_id)}
            disabled={disabled || busy}
          >
            Remove
          </button>
        </div>
      ))}

      {!photo.file && !cameraOpen && !skipping && (
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
          <button
            type="button"
            className="ak-btn ak-btn--primary ak-btn--sm"
            onClick={() => setCameraOpen(true)}
            disabled={disabled || busy}
          >
            📷 Take photo
          </button>
          <input
            id={`evidence-file-${requirement}`}
            className="ak-input"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            onChange={(e) => photo.setFromFile(e.target.files?.[0] || null)}
            disabled={disabled || busy}
            style={{ maxWidth: 240 }}
          />
          <button
            type="button"
            className="ak-btn ak-btn--sm"
            onClick={() => setSkipping(true)}
            disabled={disabled || busy}
          >
            Can’t provide this
          </button>
        </div>
      )}

      <CameraCapture
        isOpen={cameraOpen}
        onCapture={(f) => { photo.setFromCamera(f); setCameraOpen(false); }}
        onCancel={() => setCameraOpen(false)}
      />

      {photo.file && (
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
          {photo.previewUrl && (
            <img
              src={photo.previewUrl}
              alt={`${meta.label} preview`}
              style={{ width: 64, height: 64, objectFit: 'cover', borderRadius: 6 }}
            />
          )}
          <button
            type="button"
            className="ak-btn ak-btn--primary ak-btn--sm"
            onClick={attach}
            disabled={disabled || busy}
          >
            {busy ? 'Attaching…' : 'Attach'}
          </button>
          <button
            type="button"
            className="ak-btn ak-btn--sm"
            onClick={photo.clear}
            disabled={disabled || busy}
          >
            Discard
          </button>
        </div>
      )}

      {skipping && (
        <div className="ak-field">
          <label className="ak-label" htmlFor={`evidence-skip-${requirement}`}>
            Why can’t this be provided? *
          </label>
          <textarea
            id={`evidence-skip-${requirement}`}
            className="ak-input"
            rows={2}
            maxLength={500}
            value={skipReason}
            onChange={(e) => setSkipReason(e.target.value)}
            placeholder="e.g. the box was already discarded"
            disabled={disabled || busy}
          />
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <button
              type="button"
              className="ak-btn ak-btn--sm ak-btn--primary"
              onClick={recordSkip}
              disabled={disabled || busy || !skipReason.trim()}
            >
              {busy ? 'Saving…' : 'Record this'}
            </button>
            <button
              type="button"
              className="ak-btn ak-btn--sm"
              onClick={() => { setSkipping(false); setSkipReason(''); }}
              disabled={disabled || busy}
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </fieldset>
  );
}
