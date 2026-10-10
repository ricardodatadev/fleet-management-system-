import { useState } from 'react';
import type { MouseEvent } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import { Drawer } from '@/components/ui';
import { useAuth } from '@/features/auth';
import { cn } from '@/lib/cn';
import { useBreakpoint } from '@/lib/useMediaQuery';
import { AppSidebar } from './AppSidebar';
import { AssistantButton } from './AssistantButton';
import { Topbar } from './Topbar';
import { UserSummary } from './UserSummary';

const MAIN_ID = 'conteudo';

/**
 * Shell autenticado (G.1/G.3): <768 menu em drawer · 768–1023 sidebar em ícones ·
 * ≥1024 sidebar fixa.
 */
export function AppLayout() {
  const auth = useAuth();
  const navigate = useNavigate();
  const breakpoint = useBreakpoint();
  const [menuRequested, setMenuRequested] = useState(false);
  // Trocar de breakpoint fecha o menu: ao voltar para o mobile ele começa fechado.
  const [prevBreakpoint, setPrevBreakpoint] = useState(breakpoint);
  if (prevBreakpoint !== breakpoint) {
    setPrevBreakpoint(breakpoint);
    setMenuRequested(false);
  }
  if (auth.status !== 'authenticated') return null;
  const { user } = auth;
  const mobile = breakpoint === 'mobile';
  const menuOpen = mobile && menuRequested;

  async function onLogout() {
    await auth.logout();
    navigate('/login', { replace: true });
  }

  function skipToContent(event: MouseEvent<HTMLAnchorElement>) {
    event.preventDefault();
    document.getElementById(MAIN_ID)?.focus();
  }

  return (
    <div className="flex min-h-screen flex-col">
      <a
        href={`#${MAIN_ID}`}
        onClick={skipToContent}
        className="sr-only z-50 inline-flex min-h-12 min-w-12 items-center rounded-control bg-brand px-4 font-bold text-on-brand focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
      >
        Ir para o conteúdo
      </a>
      <Topbar
        user={user}
        onLogout={onLogout}
        compact={mobile}
        menuOpen={menuOpen}
        onOpenMenu={mobile ? () => setMenuRequested(true) : undefined}
      />
      <div className="flex flex-1">
        {!mobile && (
          <div
            data-testid="sidebar-panel"
            className={cn(
              'sticky top-16 h-[calc(100vh-4rem)] shrink-0 overflow-y-auto border-r border-border bg-surface py-4',
              breakpoint === 'tablet' ? 'w-20 px-2' : 'w-80 px-3',
            )}
          >
            <AppSidebar collapsed={breakpoint === 'tablet'} />
          </div>
        )}
        <main
          id={MAIN_ID}
          tabIndex={-1}
          className="min-w-0 flex-1 p-4 pb-24 focus:outline-none sm:p-6"
        >
          <Outlet />
        </main>
      </div>
      {mobile && (
        <Drawer side="left" open={menuOpen} onOpenChange={setMenuRequested} title="Menu">
          <div className="flex flex-col gap-6">
            <UserSummary user={user} className="flex-col items-start gap-2" />
            <AppSidebar onNavigate={() => setMenuRequested(false)} />
          </div>
        </Drawer>
      )}
      <AssistantButton />
    </div>
  );
}
