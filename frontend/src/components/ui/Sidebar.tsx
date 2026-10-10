import { NavLink, useMatch, useResolvedPath } from 'react-router-dom';
import { forwardRef, useId } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { Badge } from './Badge';
import { Tooltip } from './Tooltip';

/** Contêiner de navegação lateral; a configuração por permissão fica em `layouts/navigation.ts`. */
export function Sidebar({
  children,
  className,
  label = 'Navegação principal',
}: {
  children: ReactNode;
  className?: string;
  label?: string;
}) {
  return (
    <nav aria-label={label} className={cn('flex flex-col gap-4', className)}>
      {children}
    </nav>
  );
}

export interface NavGroupProps {
  title: string;
  children: ReactNode;
  /** Modo ícones (768–1023px): título só para leitores de tela. */
  collapsed?: boolean;
}

export function NavGroup({ title, children, collapsed = false }: NavGroupProps) {
  const id = useId();
  return (
    <div role="group" aria-labelledby={id} className="flex flex-col gap-2">
      <p
        id={id}
        className={cn(
          'px-3 text-xs font-bold tracking-wide text-text-muted uppercase',
          collapsed && 'sr-only',
        )}
      >
        {title}
      </p>
      {/* gap-2: espaçamento mínimo de 8px entre alvos (G.1). */}
      <ul className="flex flex-col gap-2">{children}</ul>
    </div>
  );
}

export interface NavItemProps {
  label: string;
  to: string;
  icon?: ReactNode;
  /** Item de fase futura: `aria-disabled`, selo "Em breve", tooltip, sem navegação (foco permitido). */
  disabled?: boolean;
  /** Modo ícones: rótulo e selo só para leitores de tela; o tooltip mostra o rótulo. */
  collapsed?: boolean;
  /** Chamado ao navegar (ex.: fechar o menu no mobile). */
  onNavigate?: () => void;
}

const FUTURE_HINT = 'Disponível em fase futura';

export function NavItem({
  label,
  to,
  icon,
  disabled = false,
  collapsed = false,
  onNavigate,
}: NavItemProps) {
  const itemBase = cn(
    TAP_MIN_CLASSES,
    'flex w-full items-center gap-3 rounded-control text-left',
    collapsed ? 'justify-center px-0' : 'px-3',
  );
  // Modo ícones, desabilitado: ícone esmaecido (único sinal visual além do tooltip; no modo
  // completo o botão inteiro já fica com opacity-70 e o selo "Em breve").
  // Habilitado: ícone na cor do texto (ink, 17:1), acima dos 3:1 da WCAG 1.4.11.
  const iconNode = icon && (
    <span
      aria-hidden="true"
      data-testid="nav-icon"
      className={cn(
        'inline-flex size-6 shrink-0 items-center justify-center',
        disabled && collapsed && 'opacity-50',
      )}
    >
      {icon}
    </span>
  );
  const text = <span className={cn('flex-1', collapsed && 'sr-only')}>{label}</span>;

  if (disabled) {
    return (
      <li>
        <Tooltip content={collapsed ? `${label} · ${FUTURE_HINT}` : FUTURE_HINT} side="right">
          <button
            type="button"
            aria-disabled="true"
            className={cn(
              itemBase,
              'cursor-not-allowed text-text-muted',
              !collapsed && 'opacity-70',
            )}
            onClick={(event) => event.preventDefault()}
          >
            {iconNode}
            {text}
            {collapsed ? <span className="sr-only">Em breve</span> : <Badge>Em breve</Badge>}
          </button>
        </Tooltip>
      </li>
    );
  }

  return (
    <li>
      {collapsed ? (
        <Tooltip content={label} side="right">
          <ActiveLink to={to} onNavigate={onNavigate} className={itemBase}>
            {iconNode}
            {text}
          </ActiveLink>
        </Tooltip>
      ) : (
        <ActiveLink to={to} onNavigate={onNavigate} className={itemBase}>
          {iconNode}
          {text}
        </ActiveLink>
      )}
    </li>
  );
}

/**
 * NavLink com `className` em string: o Slot do Tooltip (asChild) funde `className` e quebraria a
 * forma de função do NavLink. O estado ativo segue a regra do NavLink (prefixo da rota).
 */
const ActiveLink = forwardRef<
  HTMLAnchorElement,
  {
    to: string;
    onNavigate?: () => void;
    onClick?: (event: MouseEvent<HTMLAnchorElement>) => void;
    className: string;
    children: ReactNode;
  }
>(function ActiveLink({ to, onNavigate, onClick, className, children, ...slotProps }, ref) {
  const resolved = useResolvedPath(to);
  const active = useMatch({ path: resolved.pathname, end: false }) !== null;
  return (
    <NavLink
      ref={ref}
      to={to}
      {...slotProps}
      onClick={(event) => {
        onClick?.(event);
        onNavigate?.();
      }}
      className={cn(
        className,
        'font-bold',
        active ? 'bg-brand text-on-brand' : 'text-text hover:bg-surface-muted',
      )}
    >
      {children}
    </NavLink>
  );
});
