import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In development the API is proxied so the browser sees a single origin.
// In production set VITE_API_URL to the backend origin instead.
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
