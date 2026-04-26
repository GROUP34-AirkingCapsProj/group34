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

export const toast = {
  success: (title = 'Your work has been saved') => {
    Toast.fire({ icon: 'success', title });
  },
  error: (title = 'Something went wrong') => {
    Toast.fire({ icon: 'error', title, timer: 2500 });
  },
  info: (title) => {
    Toast.fire({ icon: 'info', title });
  },
  warning: (title) => {
    Toast.fire({ icon: 'warning', title, timer: 5500 });
  },
};
