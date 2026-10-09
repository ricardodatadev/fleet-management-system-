/**
 * Guarda da identidade visual (F1-36): cor só por token semântico, e os tokens com contraste AA.
 * Lê os fontes como texto (import.meta.glob ?raw), sem depender do DOM.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { TOKENS_FILE, findColorViolations, findScaleViolations } from './tokenGuard';
import type { Violation } from './tokenGuard';

// O Vitest roda com `css: false` (import de CSS vem vazio): lê o arquivo direto, a partir de frontend/.
const css = readFileSync(resolve(process.cwd(), 'src/styles/index.css'), 'utf8');

const sources = import.meta.glob<string>(['/src/**/*.{ts,tsx}', '!/src/api/schema.d.ts'], {
  query: '?raw',
  import: 'default',
  eager: true,
});
const SELF = '/src/styles/tokens.test.ts';

/** Violações de cor (ou de escala) em todos os fontes varridos, como `caminho: trecho`. */
function offenders(
  find: (path: string, source: string) => Violation[],
  skip: (path: string) => boolean = () => false,
) {
  return Object.entries(sources)
    .filter(([path]) => path !== SELF && !skip(path))
    .flatMap(([path, source]) => find(path, source).map((v) => `${path}: ${v.match}`));
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

describe('detector da guarda (prova negativa)', () => {
  const FILE = '/src/components/ui/Exemplo.tsx';
  const matches = (path: string, source: string) =>
    findColorViolations(path, source).map((v) => v.match);

  it.each([
    ['classe da paleta', '<div className="p-2 bg-red-50">', 'bg-red-50', 'palette'],
    ['classe com variante', "cn('flex', 'hover:text-white')", 'text-white', 'palette'],
    ['preto com opacidade', 'className="bg-black/50"', 'bg-black/50', 'palette'],
    ['borda lateral da paleta', 'className="border-l-blue-700"', 'border-l-blue-700', 'palette'],
    ['hex de 6 dígitos', "const cor = '#1d2426';", '#1d2426', 'literal'],
    ['hex de 3 dígitos', "style={{ color: '#fff' }}", '#fff', 'literal'],
    ['rgb()', "const sombra = 'rgb(0 0 0)';", 'rgb(', 'literal'],
    ['hsl()', "background: 'hsl(200 50% 40%)'", 'hsl(', 'literal'],
  ])('detecta %s fora do index.css', (_case, source, match, kind) => {
    expect(findColorViolations(FILE, source)).toContainEqual({ kind, match });
  });

  it.each([
    ['token de superfície', '<div className="bg-surface p-4">'],
    ['token de marca', "cn('text-brand', 'hover:bg-brand-hover')"],
    ['tokens de feedback', 'className="bg-danger-subtle text-danger border-warning-accent"'],
    ['texto com "red" no meio', "const label = 'Registro credenciado';"],
    ['âncora com #', "navigate('/redefinir-senha#token=abc')"],
    ['id com # e letras fora do hex', "document.querySelector('#menu-lateral')"],
  ])('não detecta %s', (_case, source) => {
    expect(matches(FILE, source)).toEqual([]);
  });

  it('no index.css: cor dentro do :root passa, fora dele é detectada', () => {
    const inTheme = ':root {\n  --bg: #f7f8f8;\n  --overlay: rgb(29 36 38 / 0.5);\n}';
    expect(findColorViolations(TOKENS_FILE, inTheme)).toEqual([]);
    expect(findColorViolations(`/${TOKENS_FILE}`, inTheme)).toEqual([]);
    expect(matches(TOKENS_FILE, `${inTheme}\nbody { color: #1d2426; }`)).toEqual(['#1d2426']);
    // Comentário com hex não conta.
    expect(matches(TOKENS_FILE, `/* paleta #be3e37 */\n${inTheme}`)).toEqual([]);
  });

  it('o mesmo trecho com cor no :root, fora do index.css, é detectado', () => {
    expect(matches(FILE, ':root { --bg: #f7f8f8; }')).toEqual(['#f7f8f8']);
  });

  it('escala: detecta tamanho, peso e canto fora da escala e deixa passar os da escala', () => {
    const off = findScaleViolations('text-lg font-semibold rounded-xl rounded-l-xl text-6xl');
    expect(off.map((v) => v.match)).toEqual([
      'text-lg',
      'text-6xl',
      'font-semibold',
      'rounded-xl',
      'rounded-l-xl',
    ]);
    expect(
      findScaleViolations('text-xs text-sm text-base text-xl text-2xl text-3xl font-bold'),
    ).toEqual([]);
    expect(findScaleViolations('rounded-control rounded-card rounded-full rounded-l-card')).toEqual(
      [],
    );
  });
});

describe('tokens de cor (F1-36)', () => {
  it('nenhuma classe da paleta padrão do Tailwind nem cor literal nos fontes', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(50);
    expect(offenders(findColorViolations)).toEqual([]);
  });

  it('no index.css, cor literal só no bloco do tema (:root)', () => {
    expect(findColorViolations(TOKENS_FILE, css)).toEqual([]);
  });

  it('tipografia, pesos e cantos só da escala (12–32 px, 400/700, 4/8 px)', () => {
    expect(
      offenders(
        (_path, source) => findScaleViolations(source),
        (path) => path.endsWith('.test.ts'),
      ),
    ).toEqual([]);
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
