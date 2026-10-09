import { Landmark } from 'lucide-react';
import { ROLE_LABELS } from '@/features/auth';
import type { AuthUser } from '@/features/auth';
import { cn } from '@/lib/cn';

/** Filial + nome/perfil do usuário (topbar e cabeçalho do menu mobile). */
export function UserSummary({ user, className }: { user: AuthUser; className?: string }) {
  return (
    <div className={cn('flex items-center gap-4', className)}>
      <p className="flex items-center gap-2 text-text-muted">
        <Landmark aria-hidden="true" className="size-5 shrink-0" />
        <span>
          <span className="sr-only">Filial: </span>
          {user.branch ? user.branch.name : 'Todas as filiais'}
        </span>
      </p>
      <p className="flex flex-col leading-tight">
        <span className="font-bold text-text">{user.name}</span>
        <span className="text-sm text-text-muted">{ROLE_LABELS[user.role]}</span>
      </p>
    </div>
  );
}
