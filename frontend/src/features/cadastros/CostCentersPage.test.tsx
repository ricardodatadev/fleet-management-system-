import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import type { Role } from '@/features/auth';
import { LOOKUP_PER_PAGE } from './lookups';

type BranchRecord = ReturnType<typeof branch>;
type Ref = { id: number; code: string; name: string } | null;

function costCenter(id: number, code: string, name: string, ref: Ref, over = {}) {
  return {
    id,
    code,
    name,
    branch: ref,
    branch_id: ref?.id ?? null,
    is_active: true,
    created_at: null,
    updated_at: null,
    deleted_at: null as string | null,
    ...over,
  };
}
type CostCenterRecord = ReturnType<typeof costCenter>;

const MATRIZ = { id: 1, code: 'FIL-001', name: 'Matriz' };
const NORTE = { id: 2, code: 'FIL-002', name: 'Norte' };
// Filial inativa: fora do lookup (is_active=1), mas ainda vinculada a um centro de custo.
const ANTIGA = { id: 3, code: 'FIL-003', name: 'Antiga' };
const BRANCHES: BranchRecord[] = [
  branch(MATRIZ.id, MATRIZ.code, MATRIZ.name),
  branch(NORTE.id, NORTE.code, NORTE.name),
  branch(ANTIGA.id, ANTIGA.code, ANTIGA.name, { is_active: false }),
];

function mockApis() {
  const branches = mockCrudApi<BranchRecord>('/branches', BRANCHES, {
    filters: ['is_active'],
    sortable: ['code', 'name', 'type', 'is_active', 'created_at', 'updated_at'],
    build: (body, id) => ({ ...branch(id, '', ''), ...body }) as BranchRecord,
  });
  const refs = [MATRIZ, NORTE, ANTIGA];
  const costCenters = mockCrudApi<CostCenterRecord>(
    '/cost-centers',
    [
      costCenter(1, 'CC-0101', 'Manutenção pesada', MATRIZ),
      costCenter(2, 'CC-0201', 'Oficina Norte', NORTE),
      costCenter(3, 'CC-0900', 'Corporativo', null),
      costCenter(4, 'CC-0301', 'Legado', ANTIGA),
    ],
    {
      filters: ['branch_id', 'is_active'],
      sortable: ['code', 'name', 'is_active', 'created_at', 'updated_at'],
      build: (body, id, current) => {
        const merged = {
          ...(current ?? costCenter(id, '', '', null)),
          ...body,
        } as CostCenterRecord;
        if ('branch_id' in body) merged.branch = refs.find((r) => r.id === body.branch_id) ?? null;
        return merged;
      },
    },
  );
  return { branches, costCenters };
}

async function renderPage(role: Role = 'admin', path = '/cadastros/centros-custo') {
  mockAuthApi(role);
  mockMetaEnums();
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1, name: 'Centros de Custo' });
  return view;
}

describe('Centros de Custo (F1-30)', () => {
  it('lista com filial embutida e "Sem filial" quando branch é null', async () => {
    mockApis();
    await renderPage();
    const row = (await screen.findByText('Corporativo')).closest('tr') as HTMLElement;
    expect(row).toHaveTextContent('Sem filial');
    expect(screen.getByText('Manutenção pesada').closest('tr')).toHaveTextContent(
      'FIL-001 — Matriz',
    );
  });

  it('filtro de filial usa o lookup (per_page ≤ 200, is_active=1) e envia branch_id', async () => {
    const { branches, costCenters } = mockApis();
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Corporativo');

    const lookup = branches.listRequests.at(-1)?.searchParams;
    expect(Number(lookup?.get('per_page'))).toBeLessThanOrEqual(200);
    expect(Number(lookup?.get('per_page'))).toBe(LOOKUP_PER_PAGE);
    expect(lookup?.get('is_active')).toBe('1');

    const filter = screen.getByRole('combobox', { name: 'Filial' });
    await waitFor(() => expect(filter).toBeEnabled());
    expect(within(filter).queryByRole('option', { name: /Antiga/ })).not.toBeInTheDocument();
    await user.selectOptions(filter, String(NORTE.id));
    await waitFor(() => expect(costCenters.lastListParams().get('branch_id')).toBe('2'));
    await waitFor(() => expect(screen.queryByText('Corporativo')).not.toBeInTheDocument());
    expect(screen.getByText('Oficina Norte')).toBeInTheDocument();
  });

  it('cria sem filial (branch_id null) e com filial (branch_id numérico)', async () => {
    const { costCenters } = mockApis();
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Corporativo');

    async function create(code: string, name: string, branchId?: string) {
      await user.click(screen.getByRole('button', { name: 'Novo centro de custo' }));
      const dialog = await screen.findByRole('dialog', { name: 'Novo centro de custo' });
      await user.type(within(dialog).getByRole('textbox', { name: /Código/ }), code);
      await user.type(within(dialog).getByRole('textbox', { name: /Nome/ }), name);
      const select = within(dialog).getByRole('combobox', { name: 'Filial' });
      // O lookup de filiais pode chegar depois da lista sob carga ("Carregando…", desabilitado).
      await waitFor(() => expect(select).toBeEnabled());
      expect(select).toHaveDisplayValue('Sem filial');
      if (branchId) await user.selectOptions(select, branchId);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    }

    await create('CC-1000', 'Diretoria');
    await create('CC-1001', 'Pátio Matriz', String(MATRIZ.id));
    expect(costCenters.writes.map((w) => w.body)).toEqual([
      { code: 'CC-1000', name: 'Diretoria', branch_id: null, is_active: true },
      { code: 'CC-1001', name: 'Pátio Matriz', branch_id: 1, is_active: true },
    ]);
    expect(await screen.findByText('Pátio Matriz')).toBeInTheDocument();
    expect(screen.getAllByText('Centro de custo cadastrado')).toHaveLength(2);
  });

  it('editar centro de custo de filial inativa mantém a filial atual selecionável', async () => {
    const { costCenters } = mockApis();
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: 'Editar CC-0301 — Legado' }));
    const dialog = await screen.findByRole('dialog', { name: 'Editar centro de custo' });
    const select = within(dialog).getByRole('combobox', { name: 'Filial' });
    await waitFor(() => expect(select).toBeEnabled());
    expect(select).toHaveDisplayValue('FIL-003 — Antiga');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(costCenters.writes[0]?.body.branch_id).toBe(3));
  });

  it('excluir com dependentes: 409 mostra a mensagem do envelope', async () => {
    const { costCenters } = mockApis();
    costCenters.deleteConflicts.set(1, {
      message: 'Não é possível excluir: existem registros ativos vinculados (equipamentos).',
      dependents: ['equipments'],
    });
    const user = userEvent.setup();
    await renderPage();
    await user.click(
      await screen.findByRole('button', { name: 'Excluir CC-0101 — Manutenção pesada' }),
    );
    const dialog = await screen.findByRole('alertdialog', { name: 'Excluir centro de custo?' });
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'existem registros ativos vinculados (equipamentos)',
    );
    expect(screen.getByText('Manutenção pesada')).toBeInTheDocument();
  });

  it('líder: vê centros de custo, mas sem botões de escrita', async () => {
    mockApis();
    await renderPage('leader');
    await screen.findByText('Corporativo');
    expect(screen.queryByRole('button', { name: 'Novo centro de custo' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Editar|Excluir/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('switch', { name: 'Mostrar excluídos' })).not.toBeInTheDocument();
  });

  it.each<Role>(['operator', 'mechanic'])('%s sem cost_centers.view → /403', async (role) => {
    const { costCenters } = mockApis();
    mockAuthApi(role);
    storeSession();
    const { location } = renderApp('/cadastros/centros-custo');
    await waitFor(() => expect(location()).toBe('/403'));
    expect(costCenters.listRequests).toHaveLength(0);
  });

  it('sem violações axe (lista e formulário)', async () => {
    mockApis();
    const user = userEvent.setup();
    const { container } = await renderPage();
    await screen.findByText('Corporativo');
    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Novo centro de custo' }));
    expect(await axe(await screen.findByRole('dialog'))).toHaveNoViolations();
  });
});
