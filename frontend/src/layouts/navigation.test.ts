import { PERMISSIONS } from '@/test/auth';
import { NAVIGATION, visibleNavigation } from './navigation';
import type { Role } from '@/features/auth';

/** Grupos e itens da spec G.3, na ordem da nota de UI. */
const G3: [string, string[]][] = [
  [
    'Painéis & Indicadores',
    [
      'Dashboards Customizados',
      'Indicadores de Manutenção',
      'Consumo & Eficiência',
      'Central de Relatórios',
    ],
  ],
  ['Gestão de Ativos & Manutenção', ['Frotas & Equipamentos', 'Compartimentos & Lubrificação']],
  [
    'Planejamento & Execução de Manutenção',
    [
      'Planos de Preventiva',
      'Pendências & Backlog',
      'Programação',
      'Agendador Inteligente',
      'Ordens de Serviço',
      'Inspeção & Checklist',
      'Apontamento de Horas',
    ],
  ],
  ['Gestão de Pneus', ['Cadastro & Estoque', 'Mapeamento nos Eixos', 'Ciclo de Vida & Recapagens']],
  ['Suprimentos & Almoxarifado', ['Abastecimentos', 'Estoque & Peças', 'Compras & Cotações']],
  ['Financeiro & Custos', ['Orçamentos', 'TCO', 'Contas a Pagar/Receber']],
  ['Telemetria & Rastreamento', ['Localizar Ativo', 'Histórico de Trajetos']],
  [
    'Cadastros Gerais',
    [
      'Pessoas & Colaboradores',
      'Unidades/Filiais',
      'Centros de Custo',
      'Famílias/Classes',
      'Parceiros & Terceiros',
    ],
  ],
  ['Administração', ['Painel de Parâmetros', 'Usuários', 'Auditoria']],
];

const can = (role: Role) => (perm: string) => PERMISSIONS[role].includes(perm);
const shape = (groups: typeof NAVIGATION) =>
  groups.map((g) => [g.title, g.items.map((i) => i.label)] as [string, string[]]);

describe('navigation.ts (G.3)', () => {
  it('lista exatamente os grupos e itens da spec, na ordem', () => {
    expect(shape(NAVIGATION)).toEqual(G3);
  });

  it('habilitados: Equipamentos, Parâmetros e os cadastros da F1-30 (Colaboradores segue na F1-31)', () => {
    const enabled = NAVIGATION.flatMap((g) => g.items.filter((i) => i.enabled));
    expect(enabled.map((i) => [i.label, i.to, i.perm])).toEqual([
      ['Frotas & Equipamentos', '/ativos/equipamentos', 'equipments.view'],
      ['Unidades/Filiais', '/cadastros/unidades', 'branches.view'],
      ['Centros de Custo', '/cadastros/centros-custo', 'cost_centers.view'],
      ['Famílias/Classes', '/cadastros/familias', 'equipment_families.view'],
      ['Painel de Parâmetros', '/parametros', 'settings.view'],
    ]);
  });

  it('ids únicos', () => {
    const ids = NAVIGATION.flatMap((g) => [g.id, ...g.items.map((i) => i.id)]);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('admin vê toda a navegação', () => {
    expect(shape(visibleNavigation(can('admin')))).toEqual(G3);
  });

  it('mecânico não vê ADMINISTRAÇÃO e vê só os cadastros que pode consultar', () => {
    const groups = shape(visibleNavigation(can('mechanic')));
    expect(groups.map(([title]) => title)).not.toContain('Administração');
    expect(groups.find(([title]) => title === 'Cadastros Gerais')?.[1]).toEqual([
      'Unidades/Filiais',
      'Famílias/Classes',
      'Parceiros & Terceiros',
    ]);
  });

  it('líder vê ADMINISTRAÇÃO apenas com o Painel de Parâmetros', () => {
    const groups = shape(visibleNavigation(can('leader')));
    expect(groups.find(([title]) => title === 'Administração')?.[1]).toEqual([
      'Painel de Parâmetros',
    ]);
  });

  it('operador: itens futuros sem permissão continuam visíveis (desabilitados)', () => {
    const groups = visibleNavigation(can('operator'));
    expect(groups.map((g) => g.title)).not.toContain('Administração');
    expect(groups.find((g) => g.id === 'paineis')?.items).toHaveLength(4);
    expect(groups.find((g) => g.id === 'cadastros')?.items.map((i) => i.label)).toEqual([
      'Unidades/Filiais',
      'Parceiros & Terceiros',
    ]);
  });
});
