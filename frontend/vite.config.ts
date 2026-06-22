import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // Served by Nginx under /app (see docker/nginx/default.conf) when built.
  base: '/app/',
  server: {
    host: true,       // listen on 0.0.0.0 so it works inside containers
    port: 5173,
    strictPort: true,
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
  },
})
