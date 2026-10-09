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
      {visibleNavigation(can).map((group) => (
        <NavGroup key={group.id} title={group.title} collapsed={collapsed}>
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
      ))}
    </Sidebar>
  );
}
