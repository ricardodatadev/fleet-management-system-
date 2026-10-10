import { ChevronDown, LogOut, Settings, UserRound } from 'lucide-react';
import { DropdownMenu } from 'radix-ui';
import type { ReactNode } from 'react';
import { Avatar, Badge } from '@/components/ui';
import { ROLE_LABELS } from '@/features/auth';
import type { AuthUser } from '@/features/auth';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';

const ITEM =
  'flex min-h-12 w-full cursor-pointer items-center gap-3 rounded-control px-3 text-left text-text outline-none data-[highlighted]:bg-surface-muted';

/** Item futuro: focável e anunciado como indisponível, sem ação (como os itens da sidebar). */
function SoonItem({ icon, label }: { icon: ReactNode; label: string }) {
  return (
    <DropdownMenu.Item
      aria-disabled="true"
      // Mantém o menu aberto e não faz nada: a tela ainda não existe.
      onSelect={(event) => event.preventDefault()}
      className={cn(ITEM, 'cursor-not-allowed text-text-muted')}
    >
      <span aria-hidden="true" className="opacity-60">
        {icon}
      </span>
      <span className="flex-1">{label}</span>
      <Badge>Em breve</Badge>
    </DropdownMenu.Item>
  );
}

export interface UserMenuProps {
  user: AuthUser;
  onLogout: () => void;
  /** Foto do usuário (futuro upload de foto); sem ela, as iniciais. */
  photoUrl?: string | null;
  /** Mostra a seta ao lado do avatar (desktop). */
  showCaret?: boolean;
}

/**
 * Menu do usuário na topbar (F1-36): avatar com as iniciais; Meu perfil e Configurações ainda
 * desabilitados; Sair faz o logout. Radix cuida de setas/Enter/Esc e devolve o foco ao avatar.
 */
export function UserMenu({ user, onLogout, photoUrl, showCaret = false }: UserMenuProps) {
  const branch = user.branch ? user.branch.name : 'Todas as filiais';
  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger
        aria-label={`Menu do usuário: ${user.name}`}
        className={cn(
          TAP_MIN_CLASSES,
          'inline-flex items-center justify-center gap-1 rounded-full px-1 text-on-topbar hover:bg-topbar-hover data-[state=open]:bg-topbar-hover',
        )}
      >
        <Avatar name={user.name} src={photoUrl} />
        {showCaret && <ChevronDown aria-hidden="true" className="size-4" />}
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align="end"
          sideOffset={8}
          className="z-50 flex w-72 flex-col gap-1 rounded-card border border-border bg-surface p-2 shadow-lg"
        >
          <DropdownMenu.Label className="flex flex-col px-3 py-2">
            <span className="font-bold text-text">{user.name}</span>
            <span className="text-sm text-text-muted">{ROLE_LABELS[user.role]}</span>
            <span className="text-sm text-text-muted">
              <span className="sr-only">Filial: </span>
              {branch}
            </span>
          </DropdownMenu.Label>
          <DropdownMenu.Separator className="my-1 h-px bg-border" />
          <SoonItem icon={<UserRound className="size-5" />} label="Meu perfil" />
          <SoonItem icon={<Settings className="size-5" />} label="Configurações" />
          <DropdownMenu.Separator className="my-1 h-px bg-border" />
          <DropdownMenu.Item onSelect={onLogout} className={ITEM}>
            <LogOut aria-hidden="true" className="size-5" />
            Sair
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  );
}
