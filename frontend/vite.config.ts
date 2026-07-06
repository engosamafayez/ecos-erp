import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
    dedupe: ['react', 'react-dom'],
  },
  optimizeDeps: {
    include: ['react', 'react-dom', 'use-sync-external-store/shim'],
  },
  // Served by Nginx under /app (see docker/nginx/default.conf) when built.
  base: '/app/',
  server: {
    host: true, // listen on 0.0.0.0 so it works inside containers
    port: 5173,
    strictPort: true,

    // Forward /api requests to the local nginx container (port 80),
    // which in turn proxies to PHP-FPM. Without this, the browser sends
    // /api/* to Vite itself (port 5173) and gets 404.
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
        secure: false,
      },
      '/storage': {
        target: 'http://localhost',
        changeOrigin: true,
        secure: false,
      },
    },
  },
  build: {
    // Output directly into backend/public/app so Nginx (docker-compose volume) can serve
    // the built SPA without running Vite. The Dockerfile Stage 2 mirrors this path
    // by creating /backend/public/app before the build runs.
    outDir: '../backend/public/app',
    emptyOutDir: true,
    sourcemap: true,
  },
});
