import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The dev server proxies the Laravel API so the SPA, its Sanctum session
// cookie and the XSRF-TOKEN cookie all live on one origin (localhost:5173).
const API_TARGET = process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: API_TARGET, changeOrigin: false },
      '/sanctum': { target: API_TARGET, changeOrigin: false },
    },
  },
  preview: {
    port: 4173,
    proxy: {
      '/api': { target: API_TARGET, changeOrigin: false },
      '/sanctum': { target: API_TARGET, changeOrigin: false },
    },
  },
  build: {
    chunkSizeWarningLimit: 700,
  },
});
