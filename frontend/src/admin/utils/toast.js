import { toast as hotToast } from 'react-hot-toast';

export const toast = {
  success: (msg) => hotToast.success(msg, { duration: 3000 }),
  error:   (msg) => hotToast.error(msg,   { duration: 4000 }),
  info:    (msg) => hotToast(msg,          { duration: 3000, icon: 'ℹ️' }),
  warning: (msg) => hotToast(msg,          { duration: 4500, icon: '⚠️' }),
};
