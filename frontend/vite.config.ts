/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The SPA talks to the REAL backend. In dev, /api is proxied to the local
// Laravel server (CORS is additionally allowed for http://localhost:5173).
// The backend remains authoritative: the proxy forwards Authorization and
// X-Swasthya-Facility/-Branch headers untouched.
const API_TARGET = process.env.SWASTHYA_API_TARGET ?? 'http://127.0.0.1:58999';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: API_TARGET,
        changeOrigin: true,
      },
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    environmentOptions: {
      jsdom: { url: 'http://localhost/' },
    },
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
  },
});
