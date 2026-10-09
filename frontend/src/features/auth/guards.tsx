import { Navigate, Outlet, useLocation, useSearchParams } from 'react-router-dom';
import type { ReactNode } from 'react';
import { Button, EmptyState } from '@/components/ui';
import { useAuth, useCan } from './auth-context';
import { loginPath, safeNext } from './safeNext';

function SessionLoading() {
  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <p role="status" className="text-ink-muted">
        Carregando sessão…
      </p>
    </main>
  );
}

function SessionError({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <EmptyState
        role="alert"
        title="Não foi possível validar sua sessão"
        description={message}
        action={<Button onClick={onRetry}>Tentar novamente</Button>}
      />
    </main>
  );
}

/** Exige sessão; sem ela vai para `/login?next=<rota atual>` (exceto após logout explícito). */
export function RequireAuth({ children }: { children?: ReactNode }) {
  const auth = useAuth();
  const location = useLocation();
  if (auth.status === 'loading') return <SessionLoading />;
  if (auth.status === 'error') return <SessionError message={auth.message} onRetry={auth.retry} />;
  if (auth.status === 'anonymous') {
    const to = auth.reason === 'logout' ? '/login' : loginPath(location);
    return <Navigate to={to} replace />;
  }
  return children ?? <Outlet />;
}

/** Exige a permissão `perm` (de /auth/me); sem ela vai para `/403`. Assume sessão (use dentro de RequireAuth). */
export function RequirePermission({ perm, children }: { perm: string; children?: ReactNode }) {
  const can = useCan();
  if (!can(perm)) return <Navigate to="/403" replace />;
  return children ?? <Outlet />;
}

/** Rotas públicas (login): quem já está logado segue para `next` validado. */
export function PublicOnly({ children }: { children: ReactNode }) {
  const auth = useAuth();
  const [params] = useSearchParams();
  if (auth.status === 'loading') return <SessionLoading />;
  if (auth.status === 'authenticated')
    return <Navigate to={safeNext(params.get('next'))} replace />;
  return children;
}
