import React from 'react';

const PR_STATUS = { PENDING: 13, APPROVED: 14, REJECTED: 15, PARTIAL: 16 };

/**
 * Reusable PR → PO progress bar.
 *
 * Props:
 *   pr          – purchase request object (required)
 *   showLabels  – render step labels below dots (default true)
 *   converting  – true when the IA is mid-way through converting (step 3 pulses)
 */
const PRProgressBar = ({ pr, showLabels = true, converting = false }) => {
  if (!pr) return null;

  const s      = pr.status_id;
  const hasPO  = !!pr.converted_to_po_id;
  const poLow  = (pr.convertedPO?.status?.status_name || '').toLowerCase();

  const reviewStatus =
    s === PR_STATUS.PENDING   ? 'pending'
    : s === PR_STATUS.REJECTED ? 'rejected'
    : 'done';

  const poStatus =
    s === PR_STATUS.REJECTED ? 'rejected'
    : hasPO                   ? 'done'
    : converting              ? 'pending'
    : 'idle';

  const rcvStatus =
    poLow.includes('complet')  ? 'done'
    : poLow.includes('partial') ? 'partial'
    : (hasPO && s !== PR_STATUS.REJECTED) ? 'pending'
    : 'idle';

  const steps = [
    { label: 'PR Submitted', status: 'done'       },
    { label: 'Admin Review', status: reviewStatus  },
    { label: 'PO Created',   status: poStatus      },
    { label: 'Receiving',    status: rcvStatus     },
  ];

  return (
    <div className="pr-progress-bar">
      {steps.map((step, i) => (
        <React.Fragment key={i}>
          <div className="pr-progress-step">
            <div className={`pr-progress-dot${step.status !== 'idle' ? ` pr-progress-dot--${step.status}` : ''}`}>
              {step.status === 'rejected' && (
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3.5" width="8" height="8">
                  <line x1="18" y1="6" x2="6" y2="18" />
                  <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
              )}
              {step.status === 'done' && (
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3.5" width="8" height="8">
                  <polyline points="20 6 9 17 4 12" />
                </svg>
              )}
            </div>
            {showLabels && <span className="pr-progress-label">{step.label}</span>}
          </div>
          {i < steps.length - 1 && (
            <div className={`pr-progress-line${step.status === 'done' ? ' pr-progress-line--done' : ''}`} />
          )}
        </React.Fragment>
      ))}
    </div>
  );
};

export const getPRStatusText = (pr) => {
  const s     = pr?.status_id;
  const hasPO = !!pr?.converted_to_po_id;
  const poLow = (pr?.convertedPO?.status?.status_name || '').toLowerCase();

  if (poLow.includes('complet'))  return '✅ Fully received';
  if (hasPO && poLow.includes('partial')) return '📦 Partially received — delivery in progress';
  if (hasPO && (poLow.includes('approv') || poLow.includes('authoriz'))) return '🚚 PO approved — awaiting delivery';
  if (hasPO)                      return '📋 PO pending Admin approval';
  if (s === PR_STATUS.REJECTED)   return '❌ Rejected';
  if (s === PR_STATUS.PARTIAL)    return '⚠ Partially ordered — some items remaining';
  if (s === PR_STATUS.APPROVED)   return '✅ Approved — ready to convert to PO';
  if (s === PR_STATUS.PENDING)    return '⏳ Awaiting Admin approval';
  return '';
};

export default PRProgressBar;
