/**
 * Guarda da identidade visual (F1-36): cor só por token semântico, e os tokens com contraste AA.
 * Lê os fontes como texto (import.meta.glob ?raw), sem depender do DOM.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// O Vitest roda com `css: false` (import de CSS vem vazio): lê o arquivo direto, a partir de frontend/.
const css = readFileSync(resolve(process.cwd(), 'src/styles/index.css'), 'utf8');

const sources = import.meta.glob<string>(['/src/**/*.{ts,tsx}', '!/src/api/schema.d.ts'], {
  query: '?raw',
  import: 'default',
  eager: true,
});
const SELF = '/src/styles/tokens.test.ts';

const PALETTE =
  'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
const UTILITY =
  'bg|text|border(?:-[trblxy])?|ring|ring-offset|outline|fill|stroke|from|via|to|divide|placeholder|accent|caret|decoration|shadow';
/** Classe de cor da paleta padrão do Tailwind (ex.: `bg-red-50`, `hover:text-white`). */
const PALETTE_CLASS = new RegExp(
  `(?<![\\w-])(?:${UTILITY})-(?:(?:${PALETTE})-\\d{2,3}|white|black)(?:/\\d+)?(?![\\w-])`,
  'g',
);
/** Cor literal: hex, rgb(), hsl(), oklch(). */
const COLOR_LITERAL = /#[0-9a-fA-F]{3,8}(?![\w-])|\b(?:rgba?|hsla?|oklch)\(/g;
/** Fora da escala (12/14/16/20/24/32 px, pesos 400/700, cantos 4/8 px). */
const OFF_SCALE = [
  /(?<![\w-])text-(?:lg|[4-9]xl)(?![\w-])/g,
  /(?<![\w-])font-(?:thin|extralight|light|medium|semibold|extrabold|black)(?![\w-])/g,
  /(?<![\w-])rounded(?:-[trblse]{1,2})?-(?:none|sm|md|lg|xl|2xl|3xl)(?![\w-])/g,
  /(?<![\w-])rounded(?=["'` ])/g,
];

function offenders(patterns: RegExp[], skip: (path: string) => boolean = () => false) {
  const found: string[] = [];
  for (const [path, source] of Object.entries(sources)) {
    if (path === SELF || skip(path)) continue;
    for (const pattern of patterns) {
      for (const match of source.matchAll(pattern)) found.push(`${path}: ${match[0]}`);
    }
  }
  return found;
}

/** Valores do tema LIGHT (`:root { --x: #… }` com cores). */
function lightTheme(): Record<string, string> {
  const block = /:root\s*{([^}]*)}/.exec(css)?.[1] ?? '';
  return Object.fromEntries(
    [...block.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{6})\b/g)].map((m) => [m[1], m[2]]),
  ) as Record<string, string>;
}

function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  }) as [number, number, number];
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number];
  return (hi + 0.05) / (lo + 0.05);
}

describe('tokens de cor (F1-36)', () => {
  it('nenhuma classe da paleta padrão do Tailwind (red-50, text-white…) nos fontes', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(50);
    expect(offenders([PALETTE_CLASS])).toEqual([]);
  });

  it('nenhuma cor literal (hex/rgb/hsl) fora do index.css', () => {
    expect(offenders([COLOR_LITERAL])).toEqual([]);
  });

  it('no index.css, cor literal só no bloco do tema (:root)', () => {
    const outsideTheme = css.replace(/:root\s*{[^}]*}/g, '').replace(/\/\*[\s\S]*?\*\//g, '');
    expect(outsideTheme.match(COLOR_LITERAL)).toBeNull();
  });

  it('tipografia, pesos e cantos só da escala (12–32 px, 400/700, 4/8 px)', () => {
    expect(offenders(OFF_SCALE, (path) => path.endsWith('.test.ts'))).toEqual([]);
  });

  it('o tema LIGHT define todos os tokens semânticos', () => {
    const theme = lightTheme();
    for (const name of [
      'bg',
      'surface',
      'surface-muted',
      'border',
      'border-strong',
      'text',
      'text-muted',
      'brand',
      'brand-hover',
      'brand-pressed',
      'brand-subtle',
      'focus',
      'success',
      'success-subtle',
      'danger',
      'danger-subtle',
      'warning',
      'warning-subtle',
      'info',
      'info-subtle',
    ]) {
      expect(Object.keys(theme)).toContain(name);
    }
    // Página sem branco puro e texto sem preto puro (pedido do usuário).
    expect(theme.bg?.toLowerCase()).not.toBe('#ffffff');
    expect(theme.text?.toLowerCase()).not.toBe('#000000');
  });

  it.each([
    // [texto, fundo, mínimo]
    ['text', 'bg', 4.5],
    ['text', 'surface', 4.5],
    ['text', 'surface-muted', 4.5],
    ['text-muted', 'bg', 4.5],
    ['text-muted', 'surface', 4.5],
    ['text-muted', 'surface-muted', 4.5],
    ['brand', 'surface', 4.5],
    ['brand', 'bg', 4.5],
    ['brand', 'brand-subtle', 4.5],
    ['on-brand', 'brand', 4.5],
    ['on-brand', 'brand-hover', 4.5],
    ['on-brand', 'brand-pressed', 4.5],
    ['on-inverse', 'inverse', 4.5],
    ['success', 'success-subtle', 4.5],
    ['danger', 'danger-subtle', 4.5],
    ['danger', 'surface', 4.5],
    ['on-danger', 'danger', 4.5],
    ['on-danger', 'danger-hover', 4.5],
    ['warning', 'warning-subtle', 4.5],
    ['info', 'info-subtle', 4.5],
    ['text', 'success-subtle', 4.5],
    ['text', 'danger-subtle', 4.5],
    ['text', 'warning-subtle', 4.5],
    ['text', 'info-subtle', 4.5],
    // Não-texto (WCAG 1.4.11): contorno de campo e foco >= 3:1.
    ['border-strong', 'surface', 3],
    ['focus', 'surface', 3],
    ['focus', 'bg', 3],
  ])('contraste %s sobre %s ≥ %s:1', (fg, bg, min) => {
    const theme = lightTheme();
    expect(contrast(theme[fg] as string, theme[bg] as string)).toBeGreaterThanOrEqual(min);
  });
});
