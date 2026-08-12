import React, { useEffect, useState } from 'react';
import { incidentReportsAPI } from '../../services/api';

/**
 * The one photo the REPORTER attached at filing, shown on the locked document.
 *
 * Distinct from the evidence checklist below the rule, which is the inspector's.
 * It is rendered in `inspect` and `decide` for the same reason every other
 * reporter field is: an inspector is checking the claim that was made, and an
 * admin is approving it. A photo that only the filing screen could show would
 * be a fact recorded and then hidden from both people who need it.
 *
 * Incident photos sit on a private disk behind an authenticated route, so an
 * <img src> would 401 — fetch a blob and hand <img> an object URL. `revoked`
 * guards both settle paths so a slow failing fetch cannot blank an image that
 * has since loaded, and the URL is released on unmount.
 */
export default function ReportPhoto({ incidentId, photoPath, photoSource }) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    setUrl(null);
    setFailed(false);
    if (!incidentId || !photoPath) return undefined;

    let revoked = false;
    let objectUrl = null;

    incidentReportsAPI.photoBlob(incidentId)
      .then((blob) => {
        if (revoked) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => { if (!revoked) setFailed(true); });

    return () => {
      revoked = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [incidentId, photoPath]);

  if (!photoPath) {
    return <p className="ak-muted" style={{ margin: 0 }}>No photo was attached at filing.</p>;
  }

  return (
    <div className="ak-field">
      <span className="ak-label">Photo filed with the report</span>
      {/* Nothing renders when photo_source is null: reports filed before the
          camera existed genuinely do not know how their photo arrived, and a
          badge on them would be an invented claim. Never "verified" either — a
          capture and a gallery pick arrive as identical uploads, so this states
          only what the app observed. */}
      {photoSource && (
        <div>
          <span className={`ak-badge ${photoSource === 'captured' ? 'ak-badge--green' : 'ak-badge--gray'}`}>
            {photoSource === 'captured' ? 'Taken in-app' : 'Uploaded from device'}
          </span>
        </div>
      )}
      {url ? (
        <img
          src={url}
          alt="Filed with the incident report"
          style={{ maxWidth: '100%', maxHeight: 320, borderRadius: 8, border: '1px solid var(--ak-line)' }}
        />
      ) : (
        // The raw storage path is meaningless to a reader, and reads identically
        // whether the fetch is in flight or has failed.
        <span className="ak-muted">
          {failed ? 'Photo attached — it could not be loaded.' : 'Loading photo…'}
        </span>
      )}
    </div>
  );
}
