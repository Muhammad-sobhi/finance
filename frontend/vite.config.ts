import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// In dev the API runs on `php artisan serve` (port 8000); in production nginx serves both
// the built SPA and /api from the same origin, so the client always calls a relative /api/v1.
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: { '/api': { target: process.env.API_PROXY ?? 'http://127.0.0.1:8000', changeOrigin: true } },
  },
  preview: {
    proxy: { '/api': { target: process.env.API_PROXY ?? 'http://127.0.0.1:8000', changeOrigin: true } },
  },
})
