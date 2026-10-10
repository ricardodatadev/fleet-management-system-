/**
 * Guarda do bundle inicial (F1-29): soma o gzip de tudo que o index.html carrega de saída
 * (scripts, CSS e modulepreload), mais as fontes woff2 do CSS, e falha acima do limite. Chunks sob demanda (ex.: o formulário de
 * equipamento) ficam de fora, porque só baixam quando usados. Rode depois do `vite build`.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const LIMIT_KB = 500;
const dist = join(import.meta.dirname, '..', 'dist');
const html = readFileSync(join(dist, 'index.html'), 'utf8');

const assets = [...html.matchAll(/(?:src|href)="\/(assets\/[^"]+\.(?:js|css))"/g)].map(
  (m) => m[1] ?? '',
);
if (assets.length === 0) {
  console.error(
    'check:bundle: nenhum asset inicial encontrado em dist/index.html (rode o build antes).',
  );
  process.exit(1);
}

let totalBytes = 0;
for (const asset of new Set(assets)) {
  const bytes = gzipSync(readFileSync(join(dist, asset))).length;
  totalBytes += bytes;
  console.log(`  ${asset}  ${(bytes / 1000).toFixed(2)} kB gz`);
}
// Fontes woff2 que o CSS inicial referencia: baixam no primeiro paint (já comprimidas, valem o
// tamanho em disco). O woff de fallback não conta: browsers atuais não o baixam.
for (const css of new Set(assets.filter((asset) => asset.endsWith('.css')))) {
  const fonts = readFileSync(join(dist, css), 'utf8').matchAll(/url\(\/(assets\/[^)]+\.woff2)\)/g);
  for (const font of new Set([...fonts].map((m) => m[1] ?? ''))) {
    const bytes = readFileSync(join(dist, font)).length;
    totalBytes += bytes;
    console.log(`  ${font}  ${(bytes / 1000).toFixed(2)} kB (woff2)`);
  }
}
const totalKb = totalBytes / 1000;
console.log(`check:bundle: inicial ${totalKb.toFixed(2)} kB gz (limite ${LIMIT_KB} kB)`);
if (totalKb > LIMIT_KB) {
  console.error(`check:bundle: bundle inicial acima de ${LIMIT_KB} kB gz.`);
  process.exit(1);
}
