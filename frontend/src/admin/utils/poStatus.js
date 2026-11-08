/** Maps a PO/PR status_name to a display label + color key (approved | pending | rejected | partial | completed | cancelled | draft). */
export const getStatusConfig = (statusName, verificationStatus = null) => {
  const s = String(statusName || '').toLowerCase();
  if (s === 'fully received')                          return { label: 'FULLY RECEIVED', color: 'completed' };
  if (s === 'short-closed')                            return { label: 'SHORT-CLOSED', color: 'completed' };
  if (s.includes('complet'))                            return { label: 'COMPLETED',          color: 'completed' };
  if (s.includes('partial'))                            return { label: 'PARTIALLY RECEIVED', color: 'partial'   };
  if (s.includes('approv') || s.includes('authoriz'))   return { label: 'APPROVED',           color: 'approved'  };
  if (s.includes('reject'))                             return { label: 'REJECTED',            color: 'rejected'  };
  if (s.includes('cancel'))                             return { label: 'CANCELLED',           color: 'cancelled' };
  if (s.includes('pending')) {
    return {
      label: verificationStatus !== 'verified' ? 'PENDING VERIFICATION' : 'PENDING APPROVAL',
      color: 'pending',
    };
  }
  return { label: String(statusName || 'DRAFT').toUpperCase(), color: 'draft' };
};
