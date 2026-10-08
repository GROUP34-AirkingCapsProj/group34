import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Barcode from 'react-barcode';
import '../../styles/barcode_zoom.css';

/**
 * A tag shown at scanning size.
 *
 * The tags in a sheet are small because a sheet is a print layout — a dozen
 * to a page — and the one in a table cell is smaller still. A handheld cannot
 * read either off a monitor, so staff end up typing the code by hand. This
 * blows one tag up to the size a scanner resolves: bar width decides that, so
 * the module width is computed from the space available rather than fixed,
 * and the bars sit on pure white with a full quiet zone either side.
 *
 * It carries its own overlay rather than the shared Modal because it opens
 * from inside one on the inventory screen. Nested Modals share a document
 * Escape handler and the body scroll lock, so closing the top one would
 * dismiss the table underneath it and hand scrolling back too early.
 */

/* Conservative CODE128 module count: 11 per character plus start, checksum,
   stop and the quiet zones. Digit-only values pack two to a symbol so the
   real bar count is lower — over-estimating only makes the bars narrower
   than they could be, which is the safe direction to be wrong in. */
const modulesFor = (value) => 11 * (String(value).length + 2) + 35;

const clamp = (n, min, max) => Math.min(max, Math.max(min, n));

/* Not decoration: a scanner needs the blank margin to find the symbol's
   edges, and 10 modules is the CODE128 minimum. */
const QUIET_MODULES = 10;

const PLATE_INSET = 32;     /* viewport gutter either side */
const PLATE_PADDING = 56;   /* the plate's own gutters */
const MAX_PLATE = 900;

function measure(value) {
  const vw = typeof window === 'undefined' ? 1024 : window.innerWidth;
  const vh = typeof window === 'undefined' ? 768 : window.innerHeight;
  const plateWidth = clamp(vw - PLATE_INSET * 2, 260, MAX_PLATE);
  return {
    plateWidth,
    moduleWidth: clamp((plateWidth - PLATE_PADDING) / modulesFor(value), 1.4, 5),
    height: Math.round(clamp(vh * 0.26, 110, 220)),
  };
}

export default function BarcodeZoom({ value, label, onClose }) {
  const [dims, setDims] = useState(() => measure(value));

  useEffect(() => {
    const onResize = () => setDims(measure(value));
    onResize();
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, [value]);

  useEffect(() => {
    /* Capture phase, and the event is stopped there: a Modal underneath
       listens for Escape on the document too, and must not also close. */
    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        onClose();
      }
    };
    document.addEventListener('keydown', onKeyDown, true);

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      document.body.style.overflow = previousOverflow;
    };
  }, [onClose]);

  if (!value) return null;

  return createPortal(
    <div
      className="bcz-backdrop no-print"
      role="dialog"
      aria-modal="true"
      aria-label={`Barcode ${value}`}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bcz-plate" style={{ width: dims.plateWidth }}>
        <div className="bcz-head">
          <span className="bcz-eyebrow">{label || 'Digital tag'}</span>
          <button type="button" className="bcz-close" onClick={onClose} aria-label="Close" autoFocus>
            &times;
          </button>
        </div>

        <div className="bcz-stage">
          <Barcode
            value={value}
            format="CODE128"
            width={dims.moduleWidth}
            height={dims.height}
            margin={0}
            marginLeft={dims.moduleWidth * QUIET_MODULES}
            marginRight={dims.moduleWidth * QUIET_MODULES}
            background="#ffffff"
            lineColor="#000000"
            displayValue={false}
          />
        </div>

        <div className="bcz-value">{value}</div>
        <p className="bcz-hint">Hold the scanner 15–20&nbsp;cm from the screen, square to the bars.</p>
      </div>
    </div>,
    document.body,
  );
}
