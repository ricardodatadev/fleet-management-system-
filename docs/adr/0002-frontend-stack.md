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
| react-hook-form / zod | 7.89.0 / 4.6.5 (F1-26; resolver próprio em `src/lib/zodResolver.ts`, sem `@hookform/resolvers`) |
| @fontsource/roboto | 5.2.8 (F1-36; só latin 400/700) |

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

`npm audit` reporta 2 vulnerabilidades **moderadas** em `react-router`/`react-router-dom` 6.x (0 high/critical no projeto):

| Alerta | Alcance | Aplica ao projeto? |
|---|---|---|
| [GHSA-wrjc-x8rr-h8h6](https://github.com/advisories/GHSA-wrjc-x8rr-h8h6): open redirect via barra invertida em `<Link>`/`useNavigate` (bypass do CVE-2025-68470) | `>=6.0.0 <7.18.0` | Só se um destino de navegação vier de entrada do usuário. |
| [GHSA-337j-9hxr-rhxg](https://github.com/advisories/GHSA-337j-9hxr-rhxg): injeção de construtor em `deserializeErrors()` no hydration de SSR | `>=6.4.0 <7.18.0` | Não: a SPA não tem SSR. O `createBrowserRouter` só leria `window.__staticRouterHydrationData`, que nada no projeto define; injetar esse global já exigiria XSS, barrado pela CSP estrita (A.4/Q6). |

- **Não há versão corrigida na linha 6** (conferido em 2026-10-10: a última é a 6.30.6, a que usamos; a correção só existe a partir da 7.18). A spec fixa React Router 6 (A.5), então a decisão da F1-29 é **manter a 6.30.6** com a mitigação abaixo.
- **Mitigação (F1-23, revalidada na F1-29):** o único destino de navegação vindo de fora é o `?next=` do login. Ele passa sempre por `safeNext` (`frontend/src/features/auth/safeNext.ts`), que aceita só path relativo iniciado por `/` e rejeita `//`, `\`, URL absoluta, `javascript:`, caracteres de controle e `/login`, com testes em `safeNext.test.ts` e `auth.test.tsx` (inclui `/\evil.example`). Auditoria dos destinos na F1-29: os demais `navigate`/`<Navigate>`/`<Link>` usam constantes (`/login`, `/403`, rota padrão, itens de `navigation.ts`) ou a própria `location` (`loginPath`, limpeza da URL na redefinição de senha).
- **Regra para as próximas fases:** nenhum destino de navegação novo pode usar entrada do usuário sem passar por `safeNext` (ou validação equivalente com teste).
- **Revisão:** avaliar o React Router 7 em fase futura, junto com a revisão do PWA/offline (D15).

## Gates de qualidade do frontend (F1-29)

- **Cobertura:** `npm run test:coverage` (parte do `npm run ci`) roda o Vitest com `thresholds` de 70% (linhas, statements, funções e branches) em `src/`; abaixo disso, falha. Relatório em `frontend/coverage/` (html e json-summary, fora do git).
- **Bundle inicial:** `npm run check:bundle` (no fim do `npm run ci`) soma o gzip dos assets que o `index.html` carrega e das fontes woff2 do CSS, com limite de 500 kB. Chunks sob demanda (ex.: o formulário de equipamento) ficam de fora.
- **Acessibilidade e toque:** `src/quality/a11y.test.tsx` (axe em Login, Equipamentos com o Drawer e Parâmetros), `src/quality/spacing.test.tsx` (≥ 8px entre alvos, G.1) e `tap-min.test.tsx` (48px).
