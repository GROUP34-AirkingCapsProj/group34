import { useRef } from 'react';

const DateInput = ({
  value, onChange, className, style,
  min, max, disabled, required,
  id, 'aria-label': ariaLabel,
}) => {
  const nativeRef = useRef(null);

  const display = (() => {
    if (!value) return '';
    const [y, m, d] = String(value).slice(0, 10).split('-');
    return m && d ? `${m}/${d}/${y}` : value;
  })();

  const open = () => {
    try { nativeRef.current?.showPicker(); } catch { /* unsupported */ }
  };

  return (
    <div style={{ position: 'relative', display: 'block' }}>
      <input
        id={id}
        type="text"
        readOnly
        value={display}
        placeholder="mm/dd/yyyy"
        className={className}
        disabled={disabled}
        aria-label={ariaLabel}
        onClick={open}
        onKeyDown={e => (e.key === 'Enter' || e.key === ' ') && open()}
        style={{ width: '100%', cursor: 'pointer', boxSizing: 'border-box', ...style, paddingRight: '2rem' }}
      />
      <svg
        onClick={open}
        width="15" height="15" viewBox="0 0 24 24"
        fill="none" stroke="currentColor" strokeWidth="2"
        strokeLinecap="round" strokeLinejoin="round"
        style={{
          position: 'absolute', right: 8, top: '50%',
          transform: 'translateY(-50%)',
          color: 'var(--ink-blue-300)',
          cursor: disabled ? 'default' : 'pointer',
          pointerEvents: disabled ? 'none' : 'auto',
        }}
      >
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/>
        <line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      <input
        ref={nativeRef}
        type="date"
        value={value || ''}
        min={min}
        max={max}
        required={required}
        onChange={onChange}
        disabled={disabled}
        tabIndex={-1}
        aria-hidden="true"
        style={{ position: 'absolute', inset: 0, opacity: 0, pointerEvents: 'none', zIndex: -1 }}
      />
    </div>
  );
};

export default DateInput;
