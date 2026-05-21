import React, { useState } from 'react';
import '../styles/archive_management.css';

/**
 * Inline archive button with confirm/cancel step — no modal.
 *
 * Props:
 *   onArchive  {() => Promise<void>}  called when user confirms
 *   disabled   {boolean}              grays out the button
 *   label      {string}               override button text (default "Archive")
 *   size       {'sm'|'md'}            size variant (default 'sm')
 */
export default function ArchiveButton({
  onArchive,
  disabled = false,
  label = 'Archive',
  size = 'sm',
}) {
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);

  const handleClick = (e) => {
    e.stopPropagation();
    if (disabled || busy) return;
    setConfirming(true);
  };

  const handleConfirm = async (e) => {
    e.stopPropagation();
    setBusy(true);
    try {
      await onArchive();
    } finally {
      setBusy(false);
      setConfirming(false);
    }
  };

  const handleCancel = (e) => {
    e.stopPropagation();
    setConfirming(false);
  };

  if (confirming) {
    return (
      <span className="archive-confirm-inline">
        <span className="archive-confirm-msg">Archive?</span>
        <button
          type="button"
          className="archive-confirm-yes"
          onClick={handleConfirm}
          disabled={busy}
        >
          {busy ? '...' : 'Yes'}
        </button>
        <button
          type="button"
          className="archive-confirm-no"
          onClick={handleCancel}
          disabled={busy}
        >
          No
        </button>
      </span>
    );
  }

  return (
    <button
      type="button"
      className={`archive-btn${size === 'md' ? ' archive-btn--md' : ''}`}
      onClick={handleClick}
      disabled={disabled || busy}
      title={disabled ? 'Cannot archive at current status' : `${label} this record`}
    >
      {label}
    </button>
  );
}
