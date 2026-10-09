# ADR-0002 — Stack do frontend (F1-21)

- **Status:** aceito (auditoria F1-21 do Claudão)
- **Data:** 2026-10-08

## Contexto

A spec Fase 1 (A.5) define a stack do frontend. Este ADR registra as versões exatas resolvidas na F1-21 (pinadas em `frontend/package.json` + `package-lock.json`, Node 24.21.0 LTS conforme D11) e os desvios de "última versão".

## Versões

| Pacote | Versão |
|---|---|
| Node (build/dev, imagem) | 24.21.0-alpine |
| vite / @vitejs/plugin-react | 8.3.4 / 6.1.2 |
| react / react-dom | 18.3.1 |
| react-router-dom | 6.30.6 |
| @tanstack/react-query | 5.104.1 |
| tailwindcss / @tailwindcss/vite | 4.3.3 |
| typescript | 5.9.3 |
| eslint / @eslint/js | 9.39.5 |
| typescript-eslint | 8.71.1 |
| eslint-plugin-jsx-a11y / react-hooks / react-refresh | 6.10.2 / 7.1.1 / 0.5.7 |
| prettier / prettier-plugin-tailwindcss | 3.9.9 / 0.8.1 |
| vitest / @vitest/coverage-v8 | 5.0.3 |
| jsdom | 30.1.2 |
| @testing-library/react / dom / jest-dom / user-event | 16.3.3 / 10.4.2 / 7.0.1 / 14.6.7 |
| msw | 2.15.0 |
| jest-axe / @types/jest-axe | 11.0.0 / 3.5.9 |
| openapi-typescript | 7.13.0 |
| radix-ui | 1.7.0 (F1-22) |
| lucide-react | 1.53.0 (F1-22) |

## Dependências adicionadas na F1-22 (componentes base)

Ambas constam da stack aprovada (A.5: "Tailwind CSS + Radix UI primitives (padrão shadcn), lucide-react"); nenhuma dependência fora da A.5 foi adicionada.

| Pacote | Por quê | Alternativas descartadas |
|---|---|---|
| `radix-ui` 1.7.0 (dependência) | Primitivos acessíveis sem estilo para os componentes com comportamento complexo de teclado/foco: Dialog (Modal, Drawer), AlertDialog (ConfirmDialog), Tabs, Tooltip, Switch, Checkbox e RadioGroup. Entregam foco preso, ESC, roving tabindex e papéis WAI-ARIA corretos, o que sustenta o critério "0 violações axe" e a navegação por teclado da G.1/G.3. Pacote único (metapacote) em vez de um `@radix-ui/react-*` por primitivo: uma versão só para pinar e auditar; o tree-shaking do Vite mantém no bundle só o que é importado. | Implementar à mão (alto risco de regressão de acessibilidade); pacotes `@radix-ui/react-*` separados (mais versões para manter alinhadas). |
| `lucide-react` 1.53.0 (dependência) | Ícones SVG (setas de ordenação, chevrons, fechar, check, spinner) como componentes React com tree-shaking por ícone. Todos usados com `aria-hidden`, o nome acessível vem do texto/`label` do controle. | SVG inline copiado (sem padronização); fontes de ícone (não fazem tree-shaking e têm pior acessibilidade). |

Não usados de propósito: `Select` é o `<select>` nativo (abre o seletor do SO em touch, mais robusto no tablet que o Select do Radix) e o `Combobox` de busca remota é próprio (WAI-ARIA 1.2), porque o Radix não tem combobox. Toast também é próprio (região `aria-live`), por ser simples e não precisar de swipe.

## Desvios de "latest"

| Item | Decisão | Motivo |
|---|---|---|
| ESLint | 9.39.5 (não 10) | `eslint-plugin-jsx-a11y` 6.10.2 declara peer `eslint` até ^9; a regra jsx-a11y é exigida pela spec. |
| TypeScript | 5.9.3 (não 6/7) | `openapi-typescript` 7.13.0 exige `typescript ^5.x` e `typescript-eslint` 8.71.1 exige `<6.1`. |
| Tailwind | 4.3.3, tokens via `@theme` em `src/styles/index.css` (sem `tailwind.config.js`) | Versão vigente; mantém `min-h-12`/`min-w-12` = 48px (`TAP_MIN`). |
| React / React Router | 18 / 6 | Definidos pela spec (A.5); versões mais novas fora do escopo. |

Reavaliar ESLint 10 e TypeScript 6+ quando jsx-a11y, openapi-typescript e typescript-eslint os suportarem.

## Risco: react-router 6 (npm audit)

`npm audit` reporta 2 vulnerabilidades moderadas em `react-router`/`react-router-dom` 6.x (open redirect via barra invertida em `<Link>`/`useNavigate`, bypass do CVE-2025-68470; e injeção de construtor em `deserializeErrors()` no hydration SSR — não aplicável, não usamos SSR). A correção existe apenas na v7 (mudança incompatível).

- **Decisão:** manter v6 na Fase 1.
- **Mitigação obrigatória na F1-23:** o parâmetro `?next=` do login só é aceito se for path relativo iniciado por `/`, rejeitando `//` e `\` (fallback para `/`), com teste. Nenhum outro redirect deve usar entrada do usuário sem essa validação.
- **Implementado (F1-23):** `frontend/src/features/auth/safeNext.ts`, usado no login e no redirect de quem já está logado; testes em `safeNext.test.ts` (inclui `//`, `\`, URL absoluta, `javascript:`, caracteres de controle e `/login`) e `auth.test.tsx`.
- **Revisão:** avaliar upgrade para React Router v7 em fase futura (junto com a revisão do PWA/offline, D15).
