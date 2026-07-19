import React from 'react';
import Modal from './Modal';
import RecordHistoryTimeline from './RecordHistoryTimeline';

/**
 * Modal showing the full, non-collapsible lifecycle history for one PR or PO.
 * `title` is the record number (e.g. "PO-0042"); `milestones` is the array from
 * purchaseOrdersAPI.history / purchaseRequestsAPI.history.
 */
export default function RecordHistoryModal({ isOpen, onClose, title, milestones, loading }) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} title={`History — ${title || ''}`} maxWidth={620}>
      <div className="rh-modal-body">
        <RecordHistoryTimeline milestones={milestones} loading={loading} />
      </div>
    </Modal>
  );
}
