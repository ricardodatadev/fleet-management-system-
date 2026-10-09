import { LogOut, Menu } from 'lucide-react';
import { Button, IconButton } from '@/components/ui';
import { APP_NAME } from '@/config/brand';
import type { AuthUser } from '@/features/auth';
import { UserSummary } from './UserSummary';

export interface TopbarProps {
  user: AuthUser;
  onLogout: () => void;
  /** Mobile: botão que abre o menu lateral. */
  onOpenMenu?: () => void;
  menuOpen?: boolean;
  compact?: boolean;
}

/** Topbar (G.3): filial do usuário, nome/perfil e logout. */
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
      className="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-4 border-b border-border bg-surface px-2 sm:px-4"
    >
      <div className="flex items-center gap-2">
        {onOpenMenu && (
          <IconButton
            label="Abrir menu"
            aria-expanded={menuOpen}
            aria-haspopup="dialog"
            icon={<Menu className="size-6" />}
            onClick={onOpenMenu}
          />
        )}
        <p className="px-2 text-xl font-bold text-brand">{APP_NAME}</p>
      </div>
      <div className="flex items-center gap-4">
        {!compact && <UserSummary user={user} />}
        {compact ? (
          <IconButton label="Sair" icon={<LogOut className="size-6" />} onClick={onLogout} />
        ) : (
          <Button variant="secondary" onClick={onLogout}>
            <LogOut aria-hidden="true" className="size-5" />
            Sair
          </Button>
        )}
      </div>
    </header>
  );
}
