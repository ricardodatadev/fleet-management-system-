import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import type { Role } from '@/features/auth';
import { FORM_SOON } from './labels';

type Ref = { id: number; code: string; name: string };
const MATRIZ: Ref = { id: 1, code: 'FIL-001', name: 'Matriz' };
const NORTE: Ref = { id: 2, code: 'FIL-002', name: 'Norte' };
const CAMINHAO: Ref = { id: 1, code: 'CAM', name: 'Caminhão pesado' };
const TRATOR: Ref = { id: 2, code: 'TRA', name: 'Trator' };

function equipment(
  id: number,
  code: string,
  name: string,
  over: Partial<{
    family: Ref;
    branch: Ref;
    plate: string | null;
    status: 'active' | 'inactive' | 'disposed';
    responsible: string | null;
    deleted_at: string | null;
    year: number | null;
  }> = {},
) {
  const family = over.family ?? CAMINHAO;
  const branchRef = over.branch ?? MATRIZ;
  return {
    id,
    code,
    name,
    family,
    family_id: family.id,
    branch: branchRef,
    branch_id: branchRef.id,
    cost_center: null,
    responsible_employee: over.responsible
      ? { id: 1, registration: 'MAT-001', name: over.responsible }
      : null,
    plate: over.plate === undefined ? `ABC${String(id).padStart(4, '0')}` : over.plate,
    serial_number: null,
    manufacturer: null,
    model: null,
    year: over.year ?? 2020,
    status: over.status ?? 'active',
    criticality: 'medium',
    criticality_source: 'family',
    criticality_override: null,
    odometer_km: 0,
    hour_meter: 0,
    acquisition_date: null,
    acquisition_value: null,
    notes: null,
    created_at: null,
    updated_at: null,
    deleted_at: over.deleted_at ?? null,
  };
}
type EquipmentRecord = ReturnType<typeof equipment>;

const SEED: EquipmentRecord[] = [
  equipment(1, 'CAM-001', 'Caminhão basculante 01', { responsible: 'João Motorista' }),
  equipment(2, 'TRA-001', 'Trator agrícola', { family: TRATOR, branch: NORTE, plate: null }),
  equipment(3, 'CAM-002', 'Caminhão baú', { status: 'disposed' }),
];

function mockApis(seed: EquipmentRecord[] = SEED) {
  mockCrudApi(
    '/branches',
    [branch(1, MATRIZ.code, MATRIZ.name), branch(2, NORTE.code, NORTE.name)],
    {
      filters: ['is_active'],
      sortable: ['code', 'name'],
      build: (body, id) => ({ ...branch(id, '', ''), ...body }),
    },
  );
  const families = mockCrudApi(
    '/equipment-families',
    [
      { ...CAMINHAO, is_active: true, deleted_at: null },
      { ...TRATOR, is_active: true, deleted_at: null },
    ],
    {
      filters: ['is_active'],
      sortable: ['code', 'name'],
      build: (body, id) => ({ id, ...body }) as never,
    },
  );
  const equipments = mockCrudApi<EquipmentRecord>('/equipments', seed, {
    searchFields: ['code', 'name', 'plate'],
    filters: ['family_id', 'branch_id', 'status'],
    sortable: ['code', 'name', 'plate', 'status', 'year', 'acquisition_date'],
    build: (body, id, current) =>
      ({ ...(current ?? equipment(id, '', '')), ...body }) as EquipmentRecord,
  });
  return { equipments, families };
}

async function renderPage(role: Role = 'admin', path = '/ativos/equipamentos') {
  mockAuthApi(role);
  mockMetaEnums();
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1, name: 'Frotas & Equipamentos' });
  return view;
}

const table = () => screen.getByRole('table', { name: 'Frotas & Equipamentos' });
const rows = () => within(table()).getAllByRole('row').slice(1);
const rowOf = (code: string) => within(table()).getByText(code).closest('tr') as HTMLElement;

describe('Frotas & Equipamentos: listagem (F1-25)', () => {
  it('colunas da nota: Código, Nome, Classe, Placa, Responsável, Filial, Status e Ações', async () => {
    mockApis();
    await renderPage();
    await screen.findByText('CAM-001');
    expect(
      within(table())
        .getAllByRole('columnheader')
        .map((th) => th.textContent),
    ).toEqual(['Código', 'Nome', 'Classe', 'Placa', 'Responsável', 'Filial', 'Status', 'Ações']);
    expect(rowOf('CAM-001')).toHaveTextContent(
      'CAM-001Caminhão basculante 01Caminhão pesadoABC0001João MotoristaMatrizAtivo',
    );
    expect(rowOf('TRA-001')).toHaveTextContent('TRA-001Trator agrícolaTrator——NorteAtivo');
    expect(rowOf('CAM-002')).toHaveTextContent('Baixado');
  });

  it('busca (debounce), filial, família e status refletem na URL e nos parâmetros da API', async () => {
    const { equipments } = mockApis();
    const user = userEvent.setup();
    const { location } = await renderPage();
    await screen.findByText('CAM-001');

    await user.type(screen.getByRole('searchbox', { name: 'Buscar' }), 'abc0001');
    await waitFor(() => expect(equipments.lastListParams().get('q')).toBe('abc0001'));
    expect(equipments.listRequests.filter((url) => url.searchParams.has('q'))).toHaveLength(1);
    await waitFor(() => expect(rows()).toHaveLength(1));
    await user.clear(screen.getByRole('searchbox', { name: 'Buscar' }));

    const branchFilter = screen.getByRole('combobox', { name: 'Filial' });
    await waitFor(() => expect(branchFilter).toBeEnabled());
    await user.selectOptions(branchFilter, '1');
    const familyFilter = screen.getByRole('combobox', { name: 'Família/Classe' });
    await waitFor(() => expect(familyFilter).toBeEnabled());
    await user.selectOptions(familyFilter, '1');
    await user.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'disposed');
    await waitFor(() => {
      const params = equipments.lastListParams();
      expect([params.get('branch_id'), params.get('family_id'), params.get('status')]).toEqual([
        '1',
        '1',
        'disposed',
      ]);
    });
    expect(location()).toContain('branch_id=1');
    expect(location()).toContain('family_id=1');
    expect(location()).toContain('status=disposed');
    await waitFor(() => expect(rows()).toHaveLength(1));
    expect(rows()[0]).toHaveTextContent('CAM-002');
  });

  it('deep-link: filtros, ordenação e página da URL vão para a primeira requisição', async () => {
    const { equipments } = mockApis();
    await renderPage('admin', '/ativos/equipamentos?status=active&sort=-name&family_id=1');
    await screen.findByText('CAM-001');
    const first = equipments.listRequests[0]?.searchParams;
    expect(first?.get('status')).toBe('active');
    expect(first?.get('sort')).toBe('-name');
    expect(first?.get('family_id')).toBe('1');
    expect(first?.get('per_page')).toBe('15');
  });

  it('ordenação só nas colunas da whitelist (asc → desc) e paginação server-side', async () => {
    const seed = Array.from({ length: 20 }, (_, i) =>
      equipment(i + 1, `EQ-${String(i + 1).padStart(3, '0')}`, `Equipamento ${i + 1}`),
    );
    const { equipments } = mockApis(seed);
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('EQ-001');
    const sortable = within(table())
      .getAllByRole('columnheader')
      .filter((th) => th.hasAttribute('aria-sort'))
      .map((th) => th.textContent);
    expect(sortable).toEqual(['Código', 'Nome', 'Placa', 'Status']);

    await user.click(screen.getByRole('button', { name: 'Placa' }));
    await waitFor(() => expect(equipments.lastListParams().get('sort')).toBe('plate'));
    await user.click(screen.getByRole('button', { name: 'Placa' }));
    await waitFor(() => expect(equipments.lastListParams().get('sort')).toBe('-plate'));
    expect(screen.queryByRole('alert')).toBeNull();

    await user.click(screen.getByRole('button', { name: /Próxima/ }));
    await waitFor(() => expect(equipments.lastListParams().get('page')).toBe('2'));
    expect(await screen.findByText('Página 2 de 2 · 20 registros')).toBeInTheDocument();
  });

  it('vazio sem filtro (com a dica do cadastro) e vazio com filtro', async () => {
    mockApis([]);
    const user = userEvent.setup();
    await renderPage();
    expect(await screen.findByText('Nenhum registro cadastrado')).toBeInTheDocument();
    expect(screen.getByText('Use "Cadastrar Nova Frota".')).toBeInTheDocument();
    await user.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'inactive');
    expect(await screen.findByText('Nenhum resultado para os filtros')).toBeInTheDocument();
  });

  it('erro 500: estado de erro com a mensagem e Tentar novamente', async () => {
    const { equipments } = mockApis();
    equipments.failLists({ status: 500, message: 'Erro interno do servidor.' });
    const user = userEvent.setup();
    await renderPage();
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Erro interno do servidor.');
    equipments.failLists(null);
    await user.click(within(alert).getByRole('button', { name: 'Tentar novamente' }));
    expect(await screen.findByText('CAM-001')).toBeInTheDocument();
  });

  it('403 da API: estado de erro com a mensagem do envelope', async () => {
    const { equipments } = mockApis();
    equipments.failLists({ status: 403, message: 'Você não tem permissão para esta ação.' });
    await renderPage();
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Você não tem permissão para esta ação.',
    );
  });

  it('operador (sem manage, com filial fixa): sem escrita, sem filtro de filial nem de família', async () => {
    const { families } = mockApis();
    await renderPage('operator');
    await screen.findByText('CAM-001');
    expect(screen.getByText('Equipamentos da filial Matriz.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cadastrar Nova Frota' })).toBeNull();
    expect(screen.queryByRole('columnheader', { name: 'Ações' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Editar|Excluir/ })).toBeNull();
    expect(screen.queryByRole('switch', { name: 'Mostrar excluídos' })).toBeNull();
    expect(screen.queryByRole('combobox', { name: 'Filial' })).toBeNull();
    // Operador não tem equipment_families.view: nem filtro nem lookup (que daria 403).
    expect(screen.queryByRole('combobox', { name: 'Família/Classe' })).toBeNull();
    expect(families.listRequests).toHaveLength(0);
    expect(screen.getByRole('combobox', { name: 'Status' })).toBeInTheDocument();
  });

  it.each<Role>(['mechanic', 'leader'])(
    '%s: filtro de família sim, de filial não; sem escrita',
    async (role) => {
      mockApis();
      await renderPage(role);
      await screen.findByText('CAM-001');
      expect(screen.getByRole('combobox', { name: 'Família/Classe' })).toBeInTheDocument();
      expect(screen.queryByRole('combobox', { name: 'Filial' })).toBeNull();
      expect(screen.queryByRole('button', { name: 'Cadastrar Nova Frota' })).toBeNull();
    },
  );

  it('Cadastrar Nova Frota e Editar estão preparados: avisam que o formulário chega em breve', async () => {
    mockApis();
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: 'Cadastrar Nova Frota' }));
    expect(await screen.findByText(FORM_SOON)).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).toBeNull();
    await user.click(
      screen.getByRole('button', { name: 'Editar CAM-001 — Caminhão basculante 01' }),
    );
    expect(screen.getAllByText(FORM_SOON).length).toBeGreaterThanOrEqual(1);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('Excluir pede confirmação e remove da lista', async () => {
    mockApis();
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: 'Excluir CAM-002 — Caminhão baú' }));
    const dialog = await screen.findByRole('alertdialog', { name: 'Excluir equipamento?' });
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));
    await waitFor(() => expect(within(table()).queryByText('CAM-002')).toBeNull());
  });

  it('alvos ≥ 48px nas ações de linha e no toolbar; sem violações axe', async () => {
    mockApis();
    const { container } = await renderPage();
    await screen.findByText('CAM-001');
    for (const control of [
      ...within(rowOf('CAM-001')).getAllByRole('button'),
      screen.getByRole('button', { name: 'Cadastrar Nova Frota' }),
      screen.getByRole('searchbox', { name: 'Buscar' }),
      screen.getByRole('combobox', { name: 'Status' }),
    ]) {
      expect(control).toHaveClass('min-h-12', 'min-w-12');
    }
    expect(await axe(container)).toHaveNoViolations();
  });
});
