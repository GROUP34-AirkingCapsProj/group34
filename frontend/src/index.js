import React from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import './admin/styles/swal-toast.css';
import './admin/styles/table_scroll.css';
import './admin/styles/theme_dark.css';
import App from './App';
import reportWebVitals from './reportWebVitals';
import { register as registerPwaServiceWorker } from './serviceWorkerRegistration';
import { ThemeProvider } from './admin/context/ThemeContext';

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(
  <React.StrictMode>
    <ThemeProvider>
      <App />
    </ThemeProvider>
  </React.StrictMode>
);

// If you want to start measuring performance in your app, pass a function
// to log results (for example: reportWebVitals(console.log))
// or send to an analytics endpoint. Learn more: https://bit.ly/CRA-vitals
reportWebVitals();
registerPwaServiceWorker();
