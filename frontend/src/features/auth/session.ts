import { APP_SLUG } from '@/config/brand';

/**
 * Persistência do token Bearer em localStorage (spec G.2, decisão D15: aprovado na Fase 1 com CSP
 * estrita; revisão obrigatória na fase do PWA). Guarda só token + expiração, nunca dados do usuário.
 */
export const SESSION_STORAGE_KEY = `${APP_SLUG}.session`;

export interface StoredSession {
  token: string;
  /** ISO-8601 UTC (expires_at do login). */
  expiresAt: string;
}

function isStoredSession(value: unknown): value is StoredSession {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as StoredSession).token === 'string' &&
    typeof (value as StoredSession).expiresAt === 'string'
  );
}

export function isExpired(session: StoredSession, now: number = Date.now()): boolean {
  const expires = Date.parse(session.expiresAt);
  return Number.isNaN(expires) || expires <= now;
}

/** Sessão salva e ainda válida; expirada ou corrompida é removida e retorna null. */
export function readSession(now: number = Date.now()): StoredSession | null {
  let raw: string | null;
  try {
    raw = localStorage.getItem(SESSION_STORAGE_KEY);
  } catch {
    return null;
  }
  if (!raw) return null;
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    parsed = null;
  }
  if (!isStoredSession(parsed) || isExpired(parsed, now)) {
    clearSession();
    return null;
  }
  return parsed;
}

export function writeSession(session: StoredSession): void {
  try {
    localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(session));
  } catch {
    // Storage indisponível (modo privado/bloqueado): a sessão vale só para esta aba.
  }
}

export function clearSession(): void {
  try {
    localStorage.removeItem(SESSION_STORAGE_KEY);
  } catch {
    // idem
  }
}
