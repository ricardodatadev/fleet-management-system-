/**
 * Detector da guarda de tokens (F1-36): funções puras sobre o texto de um arquivo, usadas pelo
 * tokens.test.ts para varrer `src/` e provadas com amostras positivas e negativas no mesmo teste.
 * Não é importado pela aplicação.
 *
 * Atenção: este arquivo também é varrido, então não escreva exemplos de classe proibida aqui.
 */

/** Arquivo de tokens: o único onde cor literal é permitida, e só no bloco do tema (`:root`). */
export const TOKENS_FILE = 'src/styles/index.css';

const PALETTE =
  'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
const UTILITY =
  'bg|text|border(?:-[trblxy])?|ring|ring-offset|outline|fill|stroke|from|via|to|divide|placeholder|accent|caret|decoration|shadow';

/** Classe de cor da paleta padrão do Tailwind (cor com tom, ou branco/preto), com ou sem variante. */
const PALETTE_CLASS = new RegExp(
  `(?<![\\w-])(?:${UTILITY})-(?:(?:${PALETTE})-\\d{2,3}|white|black)(?:/\\d+)?(?![\\w-])`,
  'g',
);
/** Cor literal: hex de 3 a 8 dígitos ou as funções de cor rgb/rgba, hsl/hsla e oklch. */
const COLOR_LITERAL = /#[0-9a-fA-F]{3,8}(?![\w-])|\b(?:rgba?|hsla?|oklch)\(/g;

/** Fora da escala: tamanhos além de 12–32 px, pesos além de 400/700, cantos além de 4/8 px. */
const OFF_SCALE = [
  /(?<![\w-])text-(?:lg|[4-9]xl)(?![\w-])/g,
  /(?<![\w-])font-(?:thin|extralight|light|medium|semibold|extrabold|black)(?![\w-])/g,
  /(?<![\w-])rounded(?:-[trblse]{1,2})?-(?:none|sm|md|lg|xl|2xl|3xl)(?![\w-])/g,
  /(?<![\w-])rounded(?=["'` ])/g,
];

export type ViolationKind = 'palette' | 'literal' | 'scale';

export interface Violation {
  kind: ViolationKind;
  match: string;
}

function collect(source: string, pattern: RegExp, kind: ViolationKind): Violation[] {
  return [...source.matchAll(pattern)].map((m) => ({ kind, match: m[0] }));
}

/** Tira comentários e o(s) bloco(s) `:root { … }` (onde ficam os valores do tema). */
function outsideTheme(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/:root\s*{[^}]*}/g, '');
}

/**
 * Violações de cor num arquivo: no arquivo de tokens, só cor literal fora do bloco do tema; nos
 * demais, classe da paleta padrão e qualquer cor literal.
 */
export function findColorViolations(path: string, source: string): Violation[] {
  if (path.replace(/^\/+/, '').endsWith(TOKENS_FILE)) {
    return collect(outsideTheme(source), COLOR_LITERAL, 'literal');
  }
  return [
    ...collect(source, PALETTE_CLASS, 'palette'),
    ...collect(source, COLOR_LITERAL, 'literal'),
  ];
}

/** Tamanho, peso ou canto fora da escala da F1-36. */
export function findScaleViolations(source: string): Violation[] {
  return OFF_SCALE.flatMap((pattern) => collect(source, pattern, 'scale'));
}
