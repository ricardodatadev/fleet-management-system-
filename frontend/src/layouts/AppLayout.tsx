import { LogOut } from 'lucide-react';
import { Outlet, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui';
import { ROLE_LABELS, useAuth } from '@/features/auth';

/**
 * Casca autenticada mínima: topbar com filial, usuário/perfil e logout.
 * A sidebar e o shell responsivo completos chegam na F1-24.
 */
export function AppLayout() {
  const auth = useAuth();
  const navigate = useNavigate();
  if (auth.status !== 'authenticated') return null;
  const { user } = auth;

  async function onLogout() {
    await auth.logout();
    navigate('/login', { replace: true });
  }

  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-border bg-surface px-4 py-2">
        <p className="text-lg font-bold text-brand-600">SIGOF-M</p>
        <div className="flex flex-wrap items-center gap-4">
          <p className="text-ink-muted">{user.branch ? user.branch.name : 'Todas as filiais'}</p>
          <p className="flex flex-col leading-tight">
            <span className="font-semibold text-ink">{user.name}</span>
            <span className="text-sm text-ink-muted">{ROLE_LABELS[user.role]}</span>
          </p>
          <Button variant="secondary" onClick={onLogout}>
            <LogOut aria-hidden="true" className="size-5" />
            Sair
          </Button>
        </div>
      </header>
      <main className="flex-1 p-4 sm:p-6">
        <Outlet />
      </main>
    </div>
  );
}
