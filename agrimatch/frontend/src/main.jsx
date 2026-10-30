import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import App from './App';
import { flushOutbox } from './lib/api';
import './index.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter><Routes><Route path="*" element={<App />} /></Routes></BrowserRouter>
  </StrictMode>,
);

// PWA: register the service worker in production builds (dev HMR stays SW-free).
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js')
      .then(() => flushOutbox())
      .catch(() => {});
  });
} else {
  window.addEventListener('load', () => flushOutbox());
}
