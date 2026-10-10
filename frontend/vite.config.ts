/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { BRAND_DEFAULTS } from './src/config/brand.ts';

// Marca (F1-33): as VITE_APP_* sempre existem no dev, no build e nos testes, para que
// `%VITE_APP_NAME%` no index.html nunca saia cru. Precedência: VITE_APP_* > APP_* (compose/.env) >
// defaults do src/config/brand.ts (único lugar com o literal).
const brandEnv = {
  VITE_APP_NAME: ['APP_NAME', BRAND_DEFAULTS.name],
  VITE_APP_FULL_NAME: ['APP_FULL_NAME', BRAND_DEFAULTS.fullName],
  VITE_APP_SLUG: ['APP_SLUG', BRAND_DEFAULTS.slug],
} as const;
for (const [key, [source, fallback]] of Object.entries(brandEnv)) {
  process.env[key] ||= process.env[source] || fallback;
}

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
      // Gate da F1-29: linhas >= 70% em src/ (os demais no mesmo patamar). Abaixo disso, o
      // `npm run test:coverage` (e o `npm run ci`) falha.
      thresholds: { lines: 70, statements: 70, functions: 70, branches: 70 },
      reporter: ['text-summary', 'json-summary', 'html'],
    },
  },
});
