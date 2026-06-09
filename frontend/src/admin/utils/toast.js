import Swal from 'sweetalert2';

const Toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 1500,
  timerProgressBar: true,
  customClass: {
    popup: 'swal-toast',
  },
  didOpen: (toast) => {
    toast.addEventListener('mouseenter', Swal.stopTimer);
    toast.addEventListener('mouseleave', Swal.resumeTimer);
  },
});

const escapeHtml = (str) =>
  String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const fire = (icon, title, subtitle, timer) => {
  const options = {
    icon,
    title,
    customClass: { popup: `swal-toast swal-toast-${icon}` },
  };
  if (subtitle) {
    options.html = `<span class="swal-toast-subtitle">${escapeHtml(subtitle)}</span>`;
  }
  if (timer) options.timer = timer;
  Toast.fire(options);
};

// Timers are bumped from the 1500ms mixin default to 2500ms so the
// title + subtitle stay on screen long enough to read; warnings linger longer.
export const toast = {
  success: (title, subtitle) => fire('success', title, subtitle, 2500),
  error:   (title, subtitle) => fire('error', title, subtitle, 2500),
  info:    (title, subtitle) => fire('info', title, subtitle, 2500),
  warning: (title, subtitle) => fire('warning', title, subtitle, 5500),
};
