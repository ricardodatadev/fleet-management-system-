/**
 * Fonte única do nome do sistema no frontend (F1-33). Os valores vêm de `VITE_APP_*`, que o
 * `vite.config.ts` garante no dev e no build a partir de `APP_*`; os defaults abaixo são o único
 * literal de marca em `src/`. Identificadores técnicos (chave de storage, device name) derivam do
 * slug, nunca do nome exibido. Trocar o nome: ver `docs/renaming.md`.
 */
export const BRAND_DEFAULTS = {
  name: 'GOF',
  fullName: 'Gestão Operacional de Frotas',
  slug: 'gof',
} as const;

// `import.meta.env` não existe quando o `vite.config.ts` (Node) importa os defaults.
const env: Partial<Record<string, string>> = import.meta.env ?? {};

/** Nome curto exibido (topbar, login, `<title>`). */
export const APP_NAME = env.VITE_APP_NAME || BRAND_DEFAULTS.name;
/** Nome completo exibido (login, `<meta name="description">`). */
export const APP_FULL_NAME = env.VITE_APP_FULL_NAME || BRAND_DEFAULTS.fullName;
/** Slug para identificadores técnicos; não deve aparecer na interface. */
export const APP_SLUG = env.VITE_APP_SLUG || BRAND_DEFAULTS.slug;
