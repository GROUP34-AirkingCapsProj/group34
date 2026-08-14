import React, { useEffect, useState } from 'react';
import { incidentReportsAPI } from '../../services/api';
import PhotoLightbox from './PhotoLightbox';

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
export function EvidenceThumbnail({
  incidentId, evidenceId, size = 64, enlargeable = false, alt = 'Incident evidence',
}) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);

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

  const img = (
    <img
      src={url}
      alt={alt}
      style={{ width: size, height: size, objectFit: 'cover', borderRadius: 6, border: '1px solid var(--ak-line)' }}
    />
  );

  if (!enlargeable) return img;

  return (
    <>
      <button
        type="button"
        // 64px cannot show whether a compressor is seized. The admin decides on
        // these photographs, so opening one full-size is not a nicety.
        title={`${alt} — click to enlarge`}
        // The <img> inside would otherwise name this button, and `title` is only
        // consulted when nothing else names an element. Say it outright.
        aria-label={`${alt} — click to enlarge`}
        onClick={() => setOpen(true)}
        style={{ background: 'none', border: 'none', padding: 0, cursor: 'zoom-in', lineHeight: 0 }}
      >
        {img}
      </button>
      {open && <PhotoLightbox url={url} alt={alt} onClose={() => setOpen(false)} />}
    </>
  );
}
