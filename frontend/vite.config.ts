/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// Em dev o Vite faz proxy de /api para o nginx (mesma origem do ponto de vista do browser).
// Host: http://localhost:8080 · Compose: VITE_API_PROXY_TARGET=http://nginx
const apiTarget = process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8080';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: { '/api': { target: apiTarget, changeOrigin: true } },
  },
  build: { sourcemap: false },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    // Testes de integração do shell (sidebar completa + jest-axe) passam de 5s com todos os
    // workers em paralelo no container.
    testTimeout: 20_000,
    coverage: {
      provider: 'v8',
      include: ['src/**'],
      exclude: ['src/api/schema.d.ts', 'src/test/**'],
    },
  },
});
