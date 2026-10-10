import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ApiError, configureApi } from '@/api';
import { authApi } from './api';
import { AuthContext } from './auth-context';
import type { AuthContextValue, AuthState, SignedOutReason } from './auth-context';
import { SESSION_STORAGE_KEY, clearSession, readSession, writeSession } from './session';
import type { StoredSession } from './session';
import type { LoginCredentials } from './types';

/** setTimeout aceita no máximo ~24,8 dias; o token vive 12 h. */
const MAX_TIMEOUT = 2_147_483_647;

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [initial] = useState(() => readSession());
  // Token salvo que ainda precisa ser validado em /auth/me (attempt força nova tentativa).
  const [pending, setPending] = useState<{ session: StoredSession; attempt: number } | null>(
    initial ? { session: initial, attempt: 0 } : null,
  );
  const [state, setState] = useState<AuthState>(
    initial ? { status: 'loading' } : { status: 'anonymous', reason: 'initial' },
  );
  const tokenRef = useRef<string | null>(initial?.token ?? null);

  const endSession = useCallback(
    (reason: SignedOutReason) => {
      clearSession();
      tokenRef.current = null;
      setPending(null);
      queryClient.clear();
      setState({ status: 'anonymous', reason });
    },
    [queryClient],
  );

  // Cliente HTTP único: token atual + qualquer 401 encerra a sessão (spec G.2).
  useEffect(() => {
    configureApi({
      getToken: () => tokenRef.current,
      onUnauthorized: () => {
        if (tokenRef.current) endSession('unauthorized');
      },
    });
    return () => configureApi({ getToken: () => null, onUnauthorized: undefined });
  }, [endSession]);

  // Valida o token salvo (reload da página).
  useEffect(() => {
    if (!pending) return;
    const controller = new AbortController();
    authApi
      .me(pending.session.token, controller.signal)
      .then((me) => {
        setPending(null);
        setState({
          status: 'authenticated',
          user: me.user,
          permissions: me.permissions,
          expiresAt: pending.session.expiresAt,
        });
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        if (error instanceof ApiError && error.isUnauthorized) return; // onUnauthorized já encerrou
        const message =
          error instanceof ApiError ? error.message : 'Não foi possível validar a sessão.';
        setState({ status: 'error', message });
      });
    return () => controller.abort();
  }, [pending]);

  // Expiração local do token (expires_at do login).
  const expiresAt = state.status === 'authenticated' ? state.expiresAt : null;
  useEffect(() => {
    if (!expiresAt) return;
    const delay = Date.parse(expiresAt) - Date.now();
    const timer = setTimeout(
      () => endSession('expired'),
      Math.min(Math.max(delay, 0), MAX_TIMEOUT),
    );
    return () => clearTimeout(timer);
  }, [expiresAt, endSession]);

  // Logout em outra aba remove a sessão do storage: encerra aqui também.
  useEffect(() => {
    function onStorage(event: StorageEvent) {
      if (event.key === SESSION_STORAGE_KEY && event.newValue === null && tokenRef.current) {
        endSession('logout');
      }
    }
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, [endSession]);

  const login = useCallback(
    async (credentials: LoginCredentials) => {
      const data = await authApi.login(credentials);
      let me: Awaited<ReturnType<typeof authApi.me>>;
      try {
        me = await authApi.me(data.token);
      } catch (error) {
        // O login emitiu um token, mas a sessão não foi montada: revoga esse token (best effort)
        // para não deixar um token válido órfão, e só então mostra o erro.
        await authApi.logout(data.token).catch(() => undefined);
        throw error;
      }
      const session = { token: data.token, expiresAt: data.expires_at };
      writeSession(session);
      tokenRef.current = session.token;
      setPending(null);
      queryClient.clear();
      setState({
        status: 'authenticated',
        user: me.user,
        permissions: me.permissions,
        expiresAt: session.expiresAt,
      });
    },
    [queryClient],
  );

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } catch {
      // Token já inválido ou rede fora: a sessão local é encerrada do mesmo jeito.
    } finally {
      endSession('logout');
    }
  }, [endSession]);

  const retry = useCallback(() => {
    const session = readSession();
    if (!session) {
      endSession('expired');
      return;
    }
    setState({ status: 'loading' });
    setPending((current) => ({ session, attempt: (current?.attempt ?? 0) + 1 }));
  }, [endSession]);

  const value = useMemo<AuthContextValue>(
    () => ({ ...state, login, logout, retry }),
    [state, login, logout, retry],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
