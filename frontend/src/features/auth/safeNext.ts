export const DEFAULT_AUTHENTICATED_PATH = '/ativos/equipamentos';

/**
 * Valida o `?next=` do login (mitigação obrigatória do ADR-0002 para o open redirect do
 * React Router 6): só aceita path relativo iniciado por `/`, rejeitando `//` e qualquer `\`.
 * Também recusa caracteres de controle e voltar para o próprio /login.
 */
export function safeNext(next: string | null | undefined): string {
  if (!next) return DEFAULT_AUTHENTICATED_PATH;
  if (!next.startsWith('/') || next.startsWith('//') || next.includes('\\')) {
    return DEFAULT_AUTHENTICATED_PATH;
  }
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f\u007f]/.test(next)) return DEFAULT_AUTHENTICATED_PATH;
  if (next === '/login' || next.startsWith('/login?') || next.startsWith('/login/')) {
    return DEFAULT_AUTHENTICATED_PATH;
  }
  return next;
}

/** Monta `/login?next=<path atual>` (path + query + hash). */
export function loginPath(from?: { pathname: string; search?: string; hash?: string }): string {
  if (!from || from.pathname === '/' || from.pathname === '/login') return '/login';
  const target = `${from.pathname}${from.search ?? ''}${from.hash ?? ''}`;
  return `/login?next=${encodeURIComponent(target)}`;
}
