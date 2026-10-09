import { Menu } from 'lucide-react';
import { IconButton } from '@/components/ui';
import { APP_NAME } from '@/config/brand';
import type { AuthUser } from '@/features/auth';
import { UserMenu } from './UserMenu';
import { UserSummary } from './UserSummary';

export interface TopbarProps {
  user: AuthUser;
  onLogout: () => void;
  /** Mobile: botão que abre o menu lateral. */
  onOpenMenu?: () => void;
  menuOpen?: boolean;
  compact?: boolean;
}

/**
 * Topbar (G.3, visual da F1-36): fundo escuro, filial e nome/perfil do usuário e o menu do
 * usuário no avatar (que concentra o Sair). No mobile fica só o avatar.
 */
export function Topbar({
  user,
  onLogout,
  onOpenMenu,
  menuOpen = false,
  compact = false,
}: TopbarProps) {
  return (
    <header
      aria-label="Barra superior"
      // Sobre o fundo escuro, o contorno de foco global usa o token claro da topbar.
      className="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-4 bg-topbar px-2 text-on-topbar [--focus:var(--topbar-focus)] sm:px-4"
    >
      <div className="flex items-center gap-2">
        {onOpenMenu && (
          <IconButton
            label="Abrir menu"
            aria-expanded={menuOpen}
            aria-haspopup="dialog"
            icon={<Menu className="size-6" />}
            onClick={onOpenMenu}
            variant="topbar"
          />
        )}
        <p className="px-2 text-xl font-bold text-on-topbar">{APP_NAME}</p>
      </div>
      <div className="flex items-center gap-4">
        {!compact && <UserSummary user={user} tone="topbar" />}
        <UserMenu user={user} onLogout={onLogout} showCaret={!compact} />
      </div>
    </header>
  );
}
