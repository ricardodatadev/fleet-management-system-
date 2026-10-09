import { Fragment } from 'react';
import { NavGroup, NavItem, Sidebar } from '@/components/ui';
import { useCan } from '@/features/auth';
import { visibleNavigation } from './navigation';

export interface AppSidebarProps {
  /** Modo ícones (tablet). */
  collapsed?: boolean;
  /** Fecha o menu mobile após navegar. */
  onNavigate?: () => void;
}

/** Sidebar gerada de `navigation.ts`, filtrada pelas permissões de /auth/me (G.3). */
export function AppSidebar({ collapsed = false, onNavigate }: AppSidebarProps) {
  const can = useCan();
  return (
    <Sidebar>
      {visibleNavigation(can).map((group, index) => (
        <Fragment key={group.id}>
          {/* Modo ícones: sem títulos visíveis, um divisor separa os grupos (decorativo; os
              grupos já são role=group rotulados). */}
          {collapsed && index > 0 && (
            <hr aria-hidden="true" data-testid="nav-separator" className="mx-2 border-border" />
          )}
          <NavGroup title={group.title} collapsed={collapsed}>
            {group.items.map((item) => (
              <NavItem
                key={item.id}
                label={item.label}
                to={item.to}
                icon={<item.icon className="size-6" />}
                disabled={!item.enabled}
                collapsed={collapsed}
                onNavigate={onNavigate}
              />
            ))}
          </NavGroup>
        </Fragment>
      ))}
    </Sidebar>
  );
}
