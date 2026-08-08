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
/** Backend rule is max:5120 (KB). Stay clearly under it. */
const MAX_BYTES = 5 * 1024 * 1024;

export default function CameraCapture({ isOpen, onCapture, onCancel }) {
  const videoRef  = useRef(null);
  const streamRef = useRef(null);
  const [phase, setPhase] = useState('requesting'); // requesting | live | review
  const [error, setError] = useState(null);
  const [stillUrl, setStillUrl] = useState(null);
  const [stillFile, setStillFile] = useState(null);

  // Bumping this re-runs the acquisition effect, which is how Retake gets a
  // fresh stream without duplicating getUserMedia.
  const [startKey, setStartKey] = useState(0);

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
    setPhase('requesting');
    setError(null);
    // Any still from a previous open is stale. Setting it to null is enough —
    // the [stillUrl] effect below revokes whatever it replaces.
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
        if (cancelled) { stream.getTracks().forEach((t) => t.stop()); return; }
        streamRef.current = stream;
        if (videoRef.current) videoRef.current.srcObject = stream;
        setPhase('live');
      } catch (err) {
        if (!cancelled) {
          setError(cameraAccessErrorMessage(err) || String(err?.message || err));
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

    const scale  = Math.min(1, MAX_EDGE / Math.max(video.videoWidth, video.videoHeight));
    const canvas = document.createElement('canvas');
    canvas.width  = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    const encode = (quality) => new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality);
    });

    (async () => {
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
    })();
  }, [stopStream]);

  const retake = useCallback(() => {
    setStillUrl(null);   // revoked by the [stillUrl] effect above
    setStillFile(null);
    // Re-arm the acquisition effect rather than duplicating getUserMedia here.
    setStartKey((k) => k + 1);
  }, []);

  const cancel = useCallback(() => { stopStream(); onCancel?.(); }, [stopStream, onCancel]);

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
          style={{ width: '100%', maxHeight: 320, borderRadius: 8, background: '#000' }}
        />
      )}

      {phase === 'review' && stillUrl && (
        <img src={stillUrl} alt="Captured evidence" style={{ width: '100%', maxHeight: 320, objectFit: 'contain', borderRadius: 8 }} />
      )}

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <button type="button" className="ak-btn" onClick={cancel}>Cancel</button>

        {phase === 'live' && (
          <button type="button" className="ak-btn ak-btn--primary" onClick={shoot}>Take photo</button>
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
