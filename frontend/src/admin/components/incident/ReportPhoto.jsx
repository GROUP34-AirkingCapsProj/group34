import React, { useEffect, useState } from 'react';
import { incidentReportsAPI } from '../../services/api';
import { PhotoSourceBadge } from './EvidencePhoto';
import PhotoLightbox from './PhotoLightbox';

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
  const [open, setOpen] = useState(false);

  useEffect(() => {
    setUrl(null);
    setFailed(false);
    // Closed when the photo identity changes, so a lightbox left open does not
    // reappear over whatever loads next.
    setOpen(false);
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
      {/* A null source renders nothing rather than "unknown" — PhotoSourceBadge
          owns that rule, and the reasoning, for all three call sites. */}
      {photoSource && (
        <div>
          <PhotoSourceBadge source={photoSource} />
        </div>
      )}
      {url ? (
        <>
          {/* Enlargeable for the same reason the evidence thumbnails are: the
              admin decides on what they can actually see, and 320px of a
              cracked panel is not always enough. Reuses the object URL already
              fetched rather than pulling the blob a second time. */}
          <button
            type="button"
            title="Photo filed with the report — click to enlarge"
            aria-label="Photo filed with the report — click to enlarge"
            onClick={() => setOpen(true)}
            style={{ background: 'none', border: 'none', padding: 0, cursor: 'zoom-in', lineHeight: 0 }}
          >
            <img
              src={url}
              alt="Filed with the incident report"
              style={{ maxWidth: '100%', maxHeight: 320, borderRadius: 8, border: '1px solid var(--ak-line)' }}
            />
          </button>
          {open && (
            <PhotoLightbox
              url={url}
              alt="Filed with the incident report"
              onClose={() => setOpen(false)}
            />
          )}
        </>
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
