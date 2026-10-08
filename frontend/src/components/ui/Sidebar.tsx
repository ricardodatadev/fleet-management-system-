import { NavLink } from 'react-router-dom';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { Badge } from './Badge';
import { Tooltip } from './Tooltip';

/** Contêiner de navegação lateral (a navegação real, por permissão, é a F1-24). */
export function Sidebar({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <nav aria-label="Navegação principal" className={cn('flex flex-col gap-4', className)}>
      {children}
    </nav>
  );
}

export function NavGroup({ title, children }: { title: string; children: ReactNode }) {
  const id = useId();
  return (
    <div role="group" aria-labelledby={id} className="flex flex-col gap-1">
      <p id={id} className="px-3 text-xs font-bold tracking-wide text-ink-muted uppercase">
        {title}
      </p>
      <ul className="flex flex-col gap-1">{children}</ul>
    </div>
  );
}

export interface NavItemProps {
  label: string;
  to: string;
  icon?: ReactNode;
  /** Item de fase futura: `aria-disabled`, selo "Em breve", tooltip, sem navegação (foco permitido). */
  disabled?: boolean;
}

const itemBase = cn(TAP_MIN_CLASSES, 'flex w-full items-center gap-3 rounded-lg px-3 text-left');

export function NavItem({ label, to, icon, disabled = false }: NavItemProps) {
  if (disabled) {
    return (
      <li>
        <Tooltip content="Disponível em fase futura" side="right">
          <button
            type="button"
            aria-disabled="true"
            className={cn(itemBase, 'cursor-not-allowed text-ink-muted opacity-70')}
            onClick={(event) => event.preventDefault()}
          >
            {icon && <span aria-hidden="true">{icon}</span>}
            <span className="flex-1">{label}</span>
            <Badge>Em breve</Badge>
          </button>
        </Tooltip>
      </li>
    );
  }
  return (
    <li>
      <NavLink
        to={to}
        className={({ isActive }) =>
          cn(
            itemBase,
            'font-semibold',
            isActive ? 'bg-brand-600 text-white' : 'text-ink hover:bg-surface-muted',
          )
        }
      >
        {icon && <span aria-hidden="true">{icon}</span>}
        <span className="flex-1">{label}</span>
      </NavLink>
    </li>
  );
}
