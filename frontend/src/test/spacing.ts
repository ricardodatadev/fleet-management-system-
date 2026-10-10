/**
 * Espaçamento mínimo entre alvos de toque (G.1: >= 8px). O jsdom não faz layout, então a regra é
 * verificada no que o layout declara: todo elemento com 2+ filhos que são alvos precisa separar os
 * filhos com gap/space de pelo menos 8px (escala Tailwind 2 = 8px).
 */

const INTERACTIVE =
  'button, a[href], input:not([type=hidden]), select, textarea, [role=tab], [role=switch], [role=radio], [role=checkbox]';

/** Blocos de estrutura: não são "alvos", são regiões (o espaçamento entre alvos fica dentro). */
const STRUCTURE = new Set([
  'HEADER',
  'MAIN',
  'NAV',
  'ASIDE',
  'SECTION',
  'FORM',
  'TABLE',
  'DL',
  'UL',
  'OL',
]);

/** gap-N, gap-x-N, gap-y-N, space-x-N, space-y-N com N >= 2 (8px). */
const SPACING = /(?:^|\s)(?:[a-z]+:)*(?:gap|gap-x|gap-y|space-x|space-y)-(\d+(?:\.\d+)?)(?=\s|$)/g;

function isHidden(element: Element): boolean {
  return element.classList.contains('sr-only') || element.closest('[hidden], .sr-only') !== null;
}

function isTarget(element: Element): boolean {
  if (STRUCTURE.has(element.tagName) || element.getAttribute('role') === 'dialog') return false;
  if (isHidden(element)) return false;
  if (element.matches(INTERACTIVE)) return true;
  // Embrulho de UM controle (FormField, gatilho de Tooltip, Switch + rótulo). Um grupo de
  // controles é contêiner: o espaçamento dele é checado nele mesmo.
  return element.querySelectorAll(INTERACTIVE).length === 1;
}

function declaredSpacing(element: Element): number {
  const classes = element.getAttribute('class') ?? '';
  let best = 0;
  for (const match of classes.matchAll(SPACING)) best = Math.max(best, Number(match[1]));
  return best;
}

export interface SpacingViolation {
  parent: string;
  targets: number;
  spacing: number;
}

function describe(element: Element): string {
  const label =
    element.getAttribute('aria-label') ??
    element.getAttribute('role') ??
    (element.getAttribute('class') ?? '').split(/\s+/).slice(0, 4).join('.');
  return `<${element.tagName.toLowerCase()}> ${label}`.trim();
}

/** Pais com 2+ alvos lado a lado e sem gap/space >= 8px. */
export function findSpacingViolations(root: ParentNode = document.body): SpacingViolation[] {
  const violations: SpacingViolation[] = [];
  for (const parent of root.querySelectorAll('*')) {
    // Tabelas: as células já se separam por padding (px-4); ações dentro da célula são checadas.
    if (['TR', 'TBODY', 'THEAD', 'TABLE', 'SELECT', 'OPTGROUP'].includes(parent.tagName)) continue;
    if (isHidden(parent)) continue;
    const targets = [...parent.children].filter(isTarget).length;
    if (targets < 2) continue;
    const spacing = declaredSpacing(parent);
    if (spacing < 2) violations.push({ parent: describe(parent), targets, spacing });
  }
  return violations;
}
