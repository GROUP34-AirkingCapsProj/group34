import Swal from 'sweetalert2';

const ConfirmDialog = Swal.mixin({
  showCancelButton: true,
  reverseButtons: true,
  focusCancel: false,
  buttonsStyling: false,
});

const toneIcon = (tone) => (tone === 'primary' ? 'question' : 'warning');
const toneClass = (tone) => (tone === 'primary' ? 'swal-confirm-primary' : 'swal-confirm-danger');

const baseClasses = (tone) => ({
  // Tone styling lives on the confirm button; the popup class is tone-agnostic.
  popup: 'swal-confirm',
  title: 'swal-confirm-title',
  htmlContainer: 'swal-confirm-text',
  confirmButton: `swal-confirm-btn ${toneClass(tone)}`,
  cancelButton: 'swal-cancel-btn',
  actions: 'swal-confirm-actions',
});

export const confirmAction = async ({
  title,
  text = '',
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  tone = 'danger',
} = {}) => {
  const result = await ConfirmDialog.fire({
    title,
    ...(text && { text }),
    icon: toneIcon(tone),
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    customClass: baseClasses(tone),
  });
  return result.isConfirmed === true;
};

export const confirmWithReason = async ({
  title,
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  tone = 'danger',
  reasonRequired = false,
  reasonLabel = 'Reason',
  reasonPlaceholder = '',
} = {}) => {
  const result = await ConfirmDialog.fire({
    title,
    icon: toneIcon(tone),
    input: 'textarea',
    inputLabel: reasonLabel,
    inputPlaceholder: reasonPlaceholder,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    customClass: { ...baseClasses(tone), input: 'swal-confirm-input' },
    preConfirm: (value) => {
      if (reasonRequired && !String(value || '').trim()) {
        Swal.showValidationMessage(`${reasonLabel} is required`);
        return false;
      }
      return value;
    },
  });
  return {
    confirmed: result.isConfirmed === true,
    reason: result.isConfirmed ? String(result.value || '').trim() : '',
  };
};
