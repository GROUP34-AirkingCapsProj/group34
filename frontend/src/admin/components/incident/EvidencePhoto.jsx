import React, { useEffect, useState } from 'react';
import { incidentReportsAPI } from '../../services/api';

/**
 * One evidence photo, fetched from the private disk.
 *
 * Evidence photos sit on the private `local` disk behind an authenticated
 * route, so an <img src> would 401 — fetch a blob and hand <img> an object URL.
 *
 * `revoked` guards both settle paths so a slow failing fetch cannot blank a
 * thumbnail that has since loaded, and the object URL is released on unmount.
 *
 * It lives in its own file because BOTH halves of the document use it now: the
 * inspector's editable checklist and the admin's read-only summary. Leaving it
 * inside EvidenceChecklist would make the read-only view import from the upload
 * UI.
 */
export function EvidenceThumbnail({ incidentId, evidenceId, size = 64 }) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let revoked = false;
    let objectUrl = null;
    setUrl(null);
    setFailed(false);

    incidentReportsAPI.evidencePhotoBlob(incidentId, evidenceId)
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
  }, [incidentId, evidenceId]);

  if (failed) {
    return <span className="ak-muted" style={{ fontSize: '0.8rem' }}>Photo attached — it could not be loaded.</span>;
  }

  if (!url) {
    return <span className="ak-muted" style={{ fontSize: '0.8rem' }}>Loading photo…</span>;
  }

  return (
    <img
      src={url}
      alt="Incident evidence"
      style={{ width: size, height: size, objectFit: 'cover', borderRadius: 6, border: '1px solid var(--ak-line)' }}
    />
  );
}
