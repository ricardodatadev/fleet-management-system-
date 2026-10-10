import {
  Activity,
  Bot,
  Boxes,
  CalendarClock,
  CalendarRange,
  ChartColumn,
  ChartLine,
  ChartPie,
  CircleDot,
  ClipboardCheck,
  ClipboardList,
  Contact,
  Droplet,
  FileText,
  Fuel,
  Gauge,
  Handshake,
  Landmark,
  LayoutDashboard,
  Layers,
  ListTodo,
  Map as MapIcon,
  MapPin,
  Package,
  PiggyBank,
  Receipt,
  Recycle,
  Route,
  ScrollText,
  ShoppingCart,
  SlidersHorizontal,
  Timer,
  Truck,
  UserCog,
  Users,
  Wallet,
  Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export interface NavItemConfig {
  id: string;
  label: string;
  icon: LucideIcon;
  /** Rota (itens futuros já têm a rota reservada da spec G.2, quando existe). */
  to: string;
  /** Permissão de /auth/me exigida para o item aparecer. Sem `perm` = visível a todos. */
  perm?: string;
  /** `false` = fase futura: `aria-disabled`, selo "Em breve", sem navegação. */
  enabled: boolean;
}

export interface NavGroupConfig {
  id: string;
  title: string;
  icon: LucideIcon;
  items: NavItemConfig[];
}

const soon = (id: string, label: string, icon: LucideIcon, perm?: string): NavItemConfig => ({
  id,
  label,
  icon,
  to: `/em-breve/${id}`,
  perm,
  enabled: false,
});

/**
 * Navegação principal (spec G.3), na ordem da nota de UI.
 * Grupo sem nenhum item visível para o usuário não é renderizado (ex.: ADMINISTRAÇÃO para quem
 * não tem settings/users/audit). Cadastros (F1-30/F1-31) e Usuários estão habilitados; Auditoria
 * segue "Em breve" (F1-32, adiada para a Fase 2).
 */
export const NAVIGATION: NavGroupConfig[] = [
  {
    id: 'paineis',
    title: 'Painéis & Indicadores',
    icon: LayoutDashboard,
    items: [
      soon('dashboards', 'Dashboards Customizados', LayoutDashboard),
      soon('indicadores-manutencao', 'Indicadores de Manutenção', Gauge),
      soon('consumo-eficiencia', 'Consumo & Eficiência', ChartLine),
      soon('relatorios', 'Central de Relatórios', FileText),
    ],
  },
  {
    id: 'ativos',
    title: 'Gestão de Ativos & Manutenção',
    icon: Truck,
    items: [
      {
        id: 'equipamentos',
        label: 'Frotas & Equipamentos',
        icon: Truck,
        to: '/ativos/equipamentos',
        perm: 'equipments.view',
        enabled: true,
      },
      soon('compartimentos', 'Compartimentos & Lubrificação', Droplet),
    ],
  },
  {
    id: 'pcm',
    title: 'Planejamento & Execução de Manutenção',
    icon: Wrench,
    items: [
      soon('planos-preventiva', 'Planos de Preventiva', ClipboardList),
      soon('pendencias', 'Pendências & Backlog', ListTodo),
      soon('programacao', 'Programação', CalendarRange),
      soon('agendador', 'Agendador Inteligente', CalendarClock),
      soon('ordens-servico', 'Ordens de Serviço', Wrench),
      soon('inspecao', 'Inspeção & Checklist', ClipboardCheck),
      soon('apontamento', 'Apontamento de Horas', Timer),
    ],
  },
  {
    id: 'pneus',
    title: 'Gestão de Pneus',
    icon: CircleDot,
    items: [
      soon('pneus-estoque', 'Cadastro & Estoque', CircleDot),
      soon('pneus-eixos', 'Mapeamento nos Eixos', Layers),
      soon('pneus-ciclo', 'Ciclo de Vida & Recapagens', Recycle),
    ],
  },
  {
    id: 'suprimentos',
    title: 'Suprimentos & Almoxarifado',
    icon: Package,
    items: [
      soon('abastecimentos', 'Abastecimentos', Fuel),
      soon('estoque-pecas', 'Estoque & Peças', Boxes),
      soon('compras', 'Compras & Cotações', ShoppingCart),
    ],
  },
  {
    id: 'financeiro',
    title: 'Financeiro & Custos',
    icon: Wallet,
    items: [
      soon('orcamentos', 'Orçamentos', PiggyBank),
      soon('tco', 'TCO', ChartPie),
      soon('contas', 'Contas a Pagar/Receber', Receipt),
    ],
  },
  {
    id: 'telemetria',
    title: 'Telemetria & Rastreamento',
    icon: MapIcon,
    items: [
      soon('localizar-ativo', 'Localizar Ativo', MapPin),
      soon('trajetos', 'Histórico de Trajetos', Route),
    ],
  },
  {
    id: 'cadastros',
    title: 'Cadastros Gerais',
    icon: Users,
    items: [
      {
        id: 'colaboradores',
        label: 'Pessoas & Colaboradores',
        icon: Contact,
        to: '/cadastros/colaboradores',
        perm: 'employees.view',
        enabled: true,
      },
      {
        id: 'unidades',
        label: 'Unidades/Filiais',
        icon: Landmark,
        to: '/cadastros/unidades',
        perm: 'branches.view',
        enabled: true,
      },
      {
        id: 'centros-custo',
        label: 'Centros de Custo',
        icon: ChartColumn,
        to: '/cadastros/centros-custo',
        perm: 'cost_centers.view',
        enabled: true,
      },
      {
        id: 'familias',
        label: 'Famílias/Classes',
        icon: Activity,
        to: '/cadastros/familias',
        perm: 'equipment_families.view',
        enabled: true,
      },
      soon('parceiros', 'Parceiros & Terceiros', Handshake),
    ],
  },
  {
    id: 'administracao',
    title: 'Administração',
    icon: UserCog,
    items: [
      {
        id: 'parametros',
        label: 'Painel de Parâmetros',
        icon: SlidersHorizontal,
        to: '/parametros',
        perm: 'settings.view',
        enabled: true,
      },
      {
        id: 'usuarios',
        label: 'Usuários',
        icon: UserCog,
        to: '/admin/usuarios',
        perm: 'users.view',
        enabled: true,
      },
      { ...soon('auditoria', 'Auditoria', ScrollText, 'audit.view'), to: '/admin/auditoria' },
    ],
  },
];

/** Grupos/itens que o usuário pode ver (itens sem `perm` são públicos aos autenticados). */
export function visibleNavigation(
  can: (permission: string) => boolean,
  navigation: NavGroupConfig[] = NAVIGATION,
): NavGroupConfig[] {
  return navigation
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.perm || can(item.perm)),
    }))
    .filter((group) => group.items.length > 0);
}

export const ASSISTANT_ICON = Bot;
