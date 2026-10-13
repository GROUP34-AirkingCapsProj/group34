import React, { useEffect, useState } from 'react';
import '../styles/scan_feedback.css';

export default function ScanFeedback({ feedback, product, quantity, unit = 'pieces', completed, target, remaining, progressLabel = 'Recorded', busy = false, overlay = false, emptyText = 'Select a product to scan' }) {
  const [expiredFeedback, setExpiredFeedback] = useState(null);
  useEffect(() => {
    if (!feedback) return;
    const timer = setTimeout(() => setExpiredFeedback(feedback), 2250);
    return () => clearTimeout(timer);
  }, [feedback]);
  const hasProgress = Number.isFinite(Number(target)) && target != null;
  const done = Number(completed) || 0;
  const total = Number(target) || 0;
  const outcome = feedback?.accepted === true ? 'success' : feedback?.accepted === false ? 'error' : 'info';
  return (
    <section className={`scan-feedback${overlay ? ' scan-feedback--overlay' : ''}`} aria-label="Scanning status">
      <div className="scan-feedback-context">
        <span className="scan-feedback-label">Currently scanning</span>
        <strong>{product || emptyText}</strong>
        {product && quantity != null && <span>Next scan: <strong>{quantity} {unit}</strong></span>}
        {product && remaining != null && <span>Remaining: <strong>{remaining} pieces</strong></span>}
        {product && hasProgress && (
          <>
            <span>{progressLabel}: <strong>{done} / {total} pieces</strong> · Remaining: <strong>{Math.max(0, total - done)} pieces</strong></span>
            <progress aria-label="Scan quantity progress" value={Math.min(done, total)} max={Math.max(total, 1)} />
          </>
        )}
        {busy && <span role="status">Checking barcode…</span>}
      </div>
      <div role="status" aria-live="polite" aria-atomic="true">
        {feedback && feedback !== expiredFeedback && !busy && (
          <div key={feedback.sequence} className={`scan-feedback-result scan-feedback-result--${outcome}`}>
            <span className="scan-feedback-label">Last scan result</span>
            <strong>{outcome === 'success' ? '✓ Scan recorded successfully' : outcome === 'error' ? '✕ Scan error' : 'Barcode identified — confirmation required'}</strong>
            <span>{feedback.message}</span>
            {feedback.product && (!overlay || feedback.product !== product) && <span>{feedback.product}</span>}
            {feedback.barcode && <code>{feedback.barcode}</code>}
          </div>
        )}
      </div>
    </section>
  );
}
