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
    // react-dismissable-layer has two different versions in the tree
    // (1.1.13 via react-dialog, 1.1.14 via react-popover). Each version
    // creates its own DismissableLayerContext singleton, so Dialog and
    // Popover end up in separate contexts: Dialog sets body.pointerEvents=none
    // but Popover never receives the counter-balancing pointer-events:auto,
    // making every option click inside a Sheet silently blocked at the CSS
    // layer. Deduplication forces a single module instance and one shared
    // context, restoring the pointer-events handshake.
    dedupe: ['react', 'react-dom', '@radix-ui/react-dismissable-layer'],
  },
  optimizeDeps: {
    include: ['react', 'react-dom'],
  },
  // Served by Nginx under /app (see docker/nginx/default.conf) when built.
  base: '/app/',
  server: {
    host: true, // listen on 0.0.0.0 so it works inside containers
    port: 5173,
    strictPort: true,

    // Forward /api and /storage requests to the local nginx container.
    //
    // BACKEND_URL is set by docker-compose.override.yml when Vite runs inside
    // Docker: "http://nginx" reaches nginx directly on ecos-network, bypassing
    // Windows wslrelay entirely. Fallback "http://127.0.0.1:8081" is used when
    // Vite runs natively on Windows — the loopback-only nginx binding added by
    // the override avoids the wslrelay "socket hang up" on that path too.
    //
    // 8081, not 8080: TASK-ENV-DUAL-STACK-DEV-ISOLATION-001 gave the DEV stack
    // its own nginx published on 127.0.0.1:8081 (docker-compose.override.yml).
    // Nothing listens on 8080, so the stale fallback made every proxied /api
    // call fail with ECONNREFUSED, which Vite surfaces to the browser as 502.
    proxy: {
      '/api': {
        target: process.env.BACKEND_URL ?? 'http://127.0.0.1:8081',
        changeOrigin: true,
        secure: false,
      },
      '/storage': {
        target: process.env.BACKEND_URL ?? 'http://127.0.0.1:8081',
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
