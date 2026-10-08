import React from 'react';
import ScanFeedback from './ScanFeedback';
import { useBarcodeCamera } from '../hooks/useBarcodeCamera';
import '../styles/barcode_scan.css';

/**
 * Global camera scanner used by the transfer dispatch/receive pages.
 * Calls onDecode(code) for each scanned barcode. `busy` disables the shutter/start.
 */
export default function CameraScanner({ onDecode, mountId, storageKey, busy = false, hint, scanInfo, statusOverlay }) {
  const {
    cameraActive, cameraError, cameraDevices, cameraSelectValue,
    startCamera, stopCamera, onCameraDeviceChange, manualFrameScan,
    isLikelyVirtualCameraLabel,
  } = useBarcodeCamera(onDecode, { mountId, storageKey });

  return (
    <>
      <div className={`bs-camera-box${scanInfo || statusOverlay ? ' bs-camera-box--feedback' : ''}`} style={{ marginBottom: 12 }}>
        {statusOverlay}
        {scanInfo && <ScanFeedback {...scanInfo} busy={busy} overlay />}
        <div id={mountId} className="bs-html5qrcode-mount" style={{ display: cameraActive ? 'block' : 'none' }} />
        {!cameraActive && (
          <div className="bs-cam-idle">
            <div className="bs-cam-icon-wrap">
              <svg viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.6)" strokeWidth="1.5" width="38" height="38">
                <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
              </svg>
            </div>
          </div>
        )}
        <div className="bs-bracket bs-tl"/><div className="bs-bracket bs-tr"/>
        <div className="bs-bracket bs-bl"/><div className="bs-bracket bs-br"/>
        {cameraActive && <div className="bs-scan-line"/>}
        {cameraActive && <p className="bs-cam-hint">{hint || 'Position the barcode in the frame and tap Scan.'}</p>}
        {cameraError && <p className="bs-cam-err">{cameraError}</p>}
        {cameraActive && (
          <button type="button" className="bs-cam-shutter" onClick={manualFrameScan} disabled={busy} aria-label="Scan barcode from current camera frame">
            <span className="bs-cam-shutter-inner"/>
            <span className="bs-cam-shutter-label">Scan</span>
          </button>
        )}
        {cameraActive
          ? <button className="bs-cam-btn bs-cam-stop" onClick={stopCamera} type="button">Stop Camera</button>
          : <button className="bs-cam-btn bs-cam-start" onClick={startCamera} type="button" disabled={busy}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
              </svg>
              Start Camera
            </button>
        }
      </div>
      {cameraDevices.length > 0 && (
        <div className="bs-cam-select-bar" style={{ marginBottom: 12 }}>
          <label htmlFor={`${mountId}-device`} className="bs-cam-select-bar-label">Camera</label>
          <select id={`${mountId}-device`} className="bs-cam-select-bar-input" value={cameraSelectValue} onChange={onCameraDeviceChange}>
            {cameraDevices.map((d) => (
              <option key={d.deviceId} value={d.deviceId}>
                {d.label}{isLikelyVirtualCameraLabel(d.label) ? ' (virtual)' : ''}
              </option>
            ))}
          </select>
        </div>
      )}
    </>
  );
}
