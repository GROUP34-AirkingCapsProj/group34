import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import '../styles/dashboard_air.css';

const Modal = ({
  isOpen,
  onClose,
  title,
  children,
  closeOnOverlayClick = true,
  maxWidth = 560,
  hideHeader = false,
}) => {
  useEffect(() => {
    const handleEscape = (e) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };

    if (isOpen) {
      document.addEventListener('keydown', handleEscape);
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.removeEventListener('keydown', handleEscape);
      document.body.style.overflow = 'unset';
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const handleOverlayClick = closeOnOverlayClick ? onClose : undefined;

  const modalMarkup = (
    <div
      className="modal user-management-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="modal-title"
      onClick={handleOverlayClick}
      style={{
        position: 'fixed',
        zIndex: 10000,
        left: 0,
        top: 0,
        width: '100%',
        height: '100%',
        background: 'rgba(0,0,0,0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20,
        boxSizing: 'border-box',
      }}
    >
      <div
        className="modal-content"
        onClick={(e) => e.stopPropagation()}
        style={{
          background: '#fff',
          width: '100%',
          maxWidth,
          maxHeight: '90vh',
          borderRadius: 12,
          boxShadow: '0 20px 60px rgba(0,0,0,0.2)',
          display: 'flex',
          flexDirection: 'column',
          overflow: 'hidden',
        }}
      >
        {!hideHeader && (
          <div
            className="modal-header"
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              padding: '18px 24px',
              borderBottom: '1px solid #e5e7eb',
              flexShrink: 0,
            }}
          >
            <h2 id="modal-title" style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#111827' }}>
              {title}
            </h2>
            <button
              type="button"
              className="close"
              onClick={onClose}
              aria-label="Close"
              style={{
                fontSize: 28,
                fontWeight: 300,
                color: '#6b7280',
                cursor: 'pointer',
                lineHeight: 1,
                padding: '0 4px',
                background: 'none',
                border: 'none',
              }}
            >
              &times;
            </button>
          </div>
        )}
        <div
          className="modal-body"
          style={{
            flex: 1,
            minHeight: 0,
            overflowY: 'auto',
            overflowX: 'hidden',
            WebkitOverflowScrolling: 'touch',
            padding: hideHeader ? '24px' : '0 24px 24px',
          }}
        >
          {children}
        </div>
      </div>
    </div>
  );

  return createPortal(modalMarkup, document.body);
};

export default Modal;
