import React, { useEffect } from 'react';

/**
 * One photo, full-size, over a dimmed page.
 *
 * Takes a URL that is ALREADY LOADED rather than fetching its own. The
 * thumbnail that opens it holds an object URL for a blob it has already pulled
 * from the private disk, and re-fetching would double the traffic and leak a
 * second object URL that nothing revokes.
 *
 * Closes on the backdrop and on Escape. The image itself stops propagation, so
 * clicking the thing you came to look at does not dismiss it.
 */
export default function PhotoLightbox({ url, alt, onClose }) {
  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={alt || 'Photo'}
      onClick={onClose}
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.82)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '2rem',
        zIndex: 2000,
      }}
    >
      <img
        src={url}
        alt={alt || 'Photo'}
        onClick={(e) => e.stopPropagation()}
        style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain', borderRadius: 8 }}
      />
      <button
        type="button"
        className="ak-btn"
        onClick={onClose}
        style={{ position: 'absolute', top: '1rem', right: '1rem' }}
      >
        Close
      </button>
    </div>
  );
}
