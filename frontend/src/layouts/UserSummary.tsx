import { Landmark } from 'lucide-react';
import { ROLE_LABELS } from '@/features/auth';
import type { AuthUser } from '@/features/auth';
import { cn } from '@/lib/cn';

const tones = {
  /** Fundo claro (cabeçalho do menu mobile). */
  default: { text: 'text-text', muted: 'text-text-muted' },
  /** Fundo escuro da topbar. */
  topbar: { text: 'text-on-topbar', muted: 'text-on-topbar-muted' },
};

/** Filial + nome/perfil do usuário (topbar e cabeçalho do menu mobile). */
export function UserSummary({
  user,
  className,
  tone = 'default',
}: {
  user: AuthUser;
  className?: string;
  tone?: keyof typeof tones;
}) {
  const colors = tones[tone];
  return (
    <div className={cn('flex items-center gap-4', className)}>
      <p className={cn('flex items-center gap-2', colors.muted)}>
        <Landmark aria-hidden="true" className="size-5 shrink-0" />
        <span>
          <span className="sr-only">Filial: </span>
          {user.branch ? user.branch.name : 'Todas as filiais'}
        </span>
      </p>
      <p className="flex flex-col leading-tight">
        <span className={cn('font-bold', colors.text)}>{user.name}</span>
        <span className={cn('text-sm', colors.muted)}>{ROLE_LABELS[user.role]}</span>
      </p>
    </div>
  );
}
