import { createContext, useCallback, useContext } from 'react';
import type { AuthUser, LoginCredentials } from './types';

/** Por que não há sessão: muda o destino do redirect e o aviso na tela de login. */
export type SignedOutReason = 'initial' | 'logout' | 'expired' | 'unauthorized';

export type AuthState =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'anonymous'; reason: SignedOutReason }
  | { status: 'authenticated'; user: AuthUser; permissions: string[]; expiresAt: string };

export type AuthContextValue = AuthState & {
  login: (credentials: LoginCredentials) => Promise<void>;
  /** Chama POST /auth/logout (falhas ignoradas) e sempre limpa a sessão local. */
  logout: () => Promise<void>;
  /** Refaz a validação do token salvo após erro de rede/servidor. */
  retry: () => void;
};

export const AuthContext = createContext<AuthContextValue | null>(null);

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth deve ser usado dentro de <AuthProvider>');
  return ctx;
}

/**
 * `can(perm)` a partir de `permissions` de /auth/me (mesma fonte do backend).
 * A UI nunca decide por nome de perfil.
 */
export function useCan(): (permission: string) => boolean {
  const auth = useAuth();
  const permissions = auth.status === 'authenticated' ? auth.permissions : null;
  return useCallback(
    (permission: string) => permissions?.includes(permission) ?? false,
    [permissions],
  );
}
