import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createServer } from 'vite';
import { afterEach, describe, expect, it } from 'vitest';
import { BRAND_DEFAULTS } from './brand';

// O Vitest roda a partir de frontend/ (o jsdom troca o `URL` global, então nada de import.meta.url).
const ROOT = resolve(process.cwd());
const INDEX_HTML = readFileSync(`${ROOT}/index.html`, 'utf8');
const KEYS = ['APP_NAME', 'APP_FULL_NAME', 'APP_SLUG'] as const;

/** Sobe o Vite com o vite.config.ts real e devolve o index.html transformado. */
async function renderIndexHtml(env: Partial<Record<string, string>>) {
  for (const key of KEYS) {
    delete process.env[key];
    delete process.env[`VITE_${key}`];
  }
  Object.assign(process.env, env);
  const server = await createServer({
    root: ROOT,
    configFile: `${ROOT}/vite.config.ts`,
    logLevel: 'silent',
    server: { middlewareMode: true, ws: false, watch: null },
  });
  try {
    return await server.transformIndexHtml('/', INDEX_HTML);
  } finally {
    await server.close();
  }
}

describe('index.html (F1-33)', () => {
  const original = { ...process.env };
  afterEach(() => {
    for (const key of KEYS) {
      for (const name of [key, `VITE_${key}`]) {
        if (original[name] === undefined) delete process.env[name];
        else process.env[name] = original[name];
      }
    }
  });

  it('o <title> e a description usam os defaults do brand.ts sem nenhuma variável', async () => {
    const html = await renderIndexHtml({});
    expect(html).toContain(`<title>${BRAND_DEFAULTS.name}</title>`);
    expect(html).toContain(`content="${BRAND_DEFAULTS.fullName}"`);
    expect(html).not.toContain('%VITE_');
  });

  it('APP_* do ambiente (compose) viram VITE_APP_* e mudam o título', async () => {
    const html = await renderIndexHtml({ APP_NAME: 'Frota X', APP_FULL_NAME: 'Frota X Operações' });
    expect(html).toContain('<title>Frota X</title>');
    expect(html).toContain('content="Frota X Operações"');
  });

  it('VITE_APP_* têm precedência sobre APP_*', async () => {
    const html = await renderIndexHtml({ APP_NAME: 'Frota X', VITE_APP_NAME: 'Frota Y' });
    expect(html).toContain('<title>Frota Y</title>');
  });
});
