import React, { useCallback, useEffect, useRef, useState } from 'react';
import { assertCameraEnvironment, cameraAccessErrorMessage } from '../hooks/useBarcodeCamera';

/**
 * Take a still photo with the device camera and hand the caller a File.
 *
 * Knows nothing about incidents — its whole contract is onCapture(file).
 *
 * Deliberately NOT built on useBarcodeCamera: that hook mounts html5-qrcode
 * into a div to decode frames and never exposes a MediaStream or <video>, so
 * there is no frame to grab. Only its error wording is reused.
 *
 * Props:
 *   isOpen    {boolean}
 *   onCapture {(file: File) => void}  fires once, with a JPEG File
 *   onCancel  {() => void}
 */

/** Long edge cap. A 12MP frame would blow past the backend's 5MB limit. */
const MAX_EDGE = 1600;
const JPEG_QUALITY = 0.85;
/**
 * Deliberately identical to the backend's max:5120 (KB): Laravel compares
 * getSize()/1024 against 5120 and both sides use a strict >, so the boundaries
 * coincide exactly and nothing this component accepts can be rejected upstream.
 * Change one and you silently open a gap — change both or neither.
 */
const MAX_BYTES = 5 * 1024 * 1024;

export default function CameraCapture({ isOpen, onCapture, onCancel }) {
  const videoRef  = useRef(null);
  const streamRef = useRef(null);
  const [phase, setPhase] = useState('requesting'); // requesting | live | review
  const [error, setError] = useState(null);
  const [stillUrl, setStillUrl] = useState(null);
  const [stillFile, setStillFile] = useState(null);
  const [busy, setBusy] = useState(false);

  // Bumping this re-runs the acquisition effect, which is how Retake gets a
  // fresh stream without duplicating getUserMedia.
  const [startKey, setStartKey] = useState(0);

  /**
   * NOT redundant with the effect's `cancelled` local. That local can only be
   * tripped by the effect's own cleanup, i.e. by isOpen going false or by a
   * retake. If a parent calls onCancel WITHOUT unmounting or closing us, the
   * cleanup never runs, so an in-flight getUserMedia would still attach and
   * turn the camera light back on after the user pressed Cancel. A ref is the
   * only thing cancel() can reach. Do not delete.
   */
  const abortRef = useRef(false);
  /** Guards a double-tap: a 12MP toBlob can run 200-500ms on mid-range Android. */
  const shootingRef = useRef(false);

  /** Every exit path must run this, or the device camera light stays on. */
  const stopStream = useCallback(() => {
    const s = streamRef.current;
    if (s) {
      s.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);

  useEffect(() => {
    if (!isOpen) return undefined;

    let cancelled = false;
    // Must be reset here, not only in cancel(): a ref persists across effect
    // runs, so a cancel that left it true would immediately poison the next
    // retake's stream.
    abortRef.current = false;
    setPhase('requesting');
    setError(null);
    // Clears the still from a previous open, or the shot being retaken. Note
    // this runs on open/retake and NOT on close — when isOpen flips false the
    // guard above returns first, so the last object URL survives until the next
    // open or until unmount. That is bounded at one URL, not a leak. Setting the
    // state to null is enough: the [stillUrl] effect below revokes what it
    // replaces.
    setStillUrl(null);
    setStillFile(null);

    (async () => {
      try {
        assertCameraEnvironment();
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' } },
        });
        // The user may have cancelled while the permission prompt was open —
        // without this the stream leaks and the camera light stays on.
        if (cancelled || abortRef.current) { stream.getTracks().forEach((t) => t.stop()); return; }
        streamRef.current = stream;
        if (videoRef.current) videoRef.current.srcObject = stream;
        // Phase flips to 'live' in the video's onLoadedMetadata, not here.
      } catch (err) {
        if (!cancelled) {
          setError(cameraAccessErrorMessage(err));
          setPhase('review');
        }
      }
    })();

    return () => { cancelled = true; stopStream(); };
  }, [isOpen, startKey, stopStream]);

  // Revokes whenever stillUrl is replaced, and on unmount. This is the ONLY
  // place object URLs are revoked — callers just set the state to null.
  useEffect(() => () => { if (stillUrl) URL.revokeObjectURL(stillUrl); }, [stillUrl]);

  const shoot = useCallback(() => {
    const video = videoRef.current;
    if (!video || !video.videoWidth) return;
    if (shootingRef.current) return;
    shootingRef.current = true;
    setBusy(true);
    // A previous "too large" / "could not be encoded" message must not survive
    // onto the review screen of a shot that then succeeded.
    setError(null);

    const scale  = Math.min(1, MAX_EDGE / Math.max(video.videoWidth, video.videoHeight));
    const canvas = document.createElement('canvas');
    canvas.width  = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    const encode = (quality) => new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality);
    });

    (async () => {
      try {
        let blob = await encode(JPEG_QUALITY);
        // One retry at lower quality beats a backend 422 the user cannot action.
        if (blob && blob.size > MAX_BYTES) blob = await encode(0.6);

        if (!blob) { setError('The photo could not be encoded. Try again.'); return; }
        if (blob.size > MAX_BYTES) {
          setError('That photo is too large even after compression. Try again further back.');
          return;
        }

        stopStream();
        setStillFile(new File([blob], 'incident.jpg', { type: 'image/jpeg' }));
        setStillUrl(URL.createObjectURL(blob));
        setPhase('review');
      } finally {
        // The early returns above leave us in 'live' so the user can retry;
        // either way the shutter has to come back.
        shootingRef.current = false;
        setBusy(false);
      }
    })();
  }, [stopStream]);

  const retake = useCallback(() => {
    setStillUrl(null);   // revoked by the [stillUrl] effect above
    setStillFile(null);
    // Re-arm the acquisition effect rather than duplicating getUserMedia here.
    setStartKey((k) => k + 1);
  }, []);

  const cancel = useCallback(() => {
    abortRef.current = true;   // see abortRef above: covers a parent that does not unmount us
    stopStream();
    onCancel?.();
  }, [stopStream, onCancel]);

  const use = useCallback(() => {
    if (!stillFile) return;
    stopStream();
    onCapture?.(stillFile);
  }, [stillFile, stopStream, onCapture]);

  if (!isOpen) return null;

  return (
    <div className="ak-field" style={{ gap: '0.6rem', border: '1px solid var(--ak-line)', borderRadius: 8, padding: '0.75rem' }}>
      {error && <div className="ak-state ak-state--error" style={{ fontSize: '0.85rem' }}>{error}</div>}

      {phase !== 'review' && (
        <video
          ref={videoRef}
          autoPlay
          muted
          playsInline   /* without this iOS Safari forces fullscreen playback */
          // videoWidth is 0 until metadata loads, so a shutter offered before
          // this point would silently produce nothing. Driving the phase from
          // the element's own event also means a 'live' phase with a null ref
          // is unreachable: no element, no event, no 'live'.
          onLoadedMetadata={() => setPhase('live')}
          // A stalled element would otherwise be a dead end: no metadata, no
          // 'live', no shutter, and no explanation for the black rectangle.
          onError={() => { setError('The camera stream failed. Try again.'); setPhase('review'); }}
          style={{ width: '100%', maxHeight: 320, borderRadius: 8, background: '#000' }}
        />
      )}

      {phase === 'requesting' && (
        <div className="ak-muted" style={{ fontSize: '0.85rem' }}>Starting camera…</div>
      )}

      {phase === 'review' && stillUrl && (
        <img src={stillUrl} alt="Captured evidence" style={{ width: '100%', maxHeight: 320, objectFit: 'contain', borderRadius: 8 }} />
      )}

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <button type="button" className="ak-btn" onClick={cancel}>Cancel</button>

        {phase === 'live' && (
          <button type="button" className="ak-btn ak-btn--primary" onClick={shoot} disabled={busy}>
            {busy ? 'Capturing…' : 'Take photo'}
          </button>
        )}

        {phase === 'review' && stillFile && (
          <>
            <button type="button" className="ak-btn" onClick={retake}>Retake</button>
            <button type="button" className="ak-btn ak-btn--primary" onClick={use}>Use photo</button>
          </>
        )}

        {phase === 'review' && !stillFile && error && (
          <button type="button" className="ak-btn" onClick={retake}>Try again</button>
        )}
      </div>
    </div>
  );
}
