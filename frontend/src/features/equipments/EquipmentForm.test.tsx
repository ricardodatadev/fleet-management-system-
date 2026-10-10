import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { http } from 'msw';
import { API, INVALID_DATA, fail, mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import { server } from '@/test/server';
import { LOOKUP_PER_PAGE } from '@/features/cadastros/lookups';
import { PLATE_MESSAGE, REQUIRED } from './schema';

type Ref = { id: number; code: string; name: string };
const MATRIZ: Ref = { id: 1, code: 'FIL-001', name: 'Matriz' };
const NORTE: Ref = { id: 2, code: 'FIL-002', name: 'Norte' };
const CAMINHAO: Ref = { id: 1, code: 'CAM', name: 'Caminhão pesado' };
const CC_MATRIZ: Ref = { id: 1, code: 'CC-0101', name: 'Oficina Matriz' };
const CC_NORTE: Ref = { id: 2, code: 'CC-0201', name: 'Oficina Norte' };
const CC_GERAL: Ref = { id: 9, code: 'CC-0900', name: 'Corporativo' };

const EXISTING = {
  id: 1,
  code: 'CAM-001',
  name: 'Caminhão basculante 01',
  family: CAMINHAO,
  family_id: 1,
  branch: MATRIZ,
  branch_id: 1,
  cost_center: CC_MATRIZ,
  responsible_employee: { id: 5, registration: 'MAT-005', name: 'João Motorista' },
  plate: 'ABC1D23',
  serial_number: 'SN-1',
  manufacturer: 'Volvo',
  model: 'FH',
  year: 2022,
  status: 'active' as const,
  criticality: 'high',
  criticality_source: 'override',
  criticality_override: 'high',
  odometer_km: 125430.5,
  hour_meter: 3210,
  acquisition_date: '2022-03-15',
  acquisition_value: 450000,
  notes: 'Revisado.',
  created_at: null,
  updated_at: null,
  deleted_at: null as string | null,
};
type EquipmentRecord = typeof EXISTING;

function mockApis() {
  mockCrudApi(
    '/branches',
    [branch(1, MATRIZ.code, MATRIZ.name), branch(2, NORTE.code, NORTE.name)],
    {
      filters: ['is_active'],
      sortable: ['code', 'name'],
      build: (body, id) => ({ ...branch(id, '', ''), ...body }),
    },
  );
  mockCrudApi('/equipment-families', [{ ...CAMINHAO, is_active: true, deleted_at: null }], {
    filters: ['is_active'],
    sortable: ['code', 'name'],
    build: (body, id) => ({ id, ...body }) as never,
  });
  mockCrudApi(
    '/cost-centers',
    [
      { ...CC_MATRIZ, branch: MATRIZ, is_active: true, deleted_at: null },
      { ...CC_NORTE, branch: NORTE, is_active: true, deleted_at: null },
      { ...CC_GERAL, branch: null, is_active: true, deleted_at: null },
    ],
    { filters: ['is_active'], sortable: ['code'], build: (body, id) => ({ id, ...body }) as never },
  );
  const employees = mockCrudApi(
    '/employees',
    [
      {
        id: 5,
        registration: 'MAT-005',
        name: 'João Motorista',
        branch_id: 1,
        is_active: true,
        deleted_at: null,
      },
      {
        id: 6,
        registration: 'MAT-006',
        name: 'Pedro Norte',
        branch_id: 2,
        is_active: true,
        deleted_at: null,
      },
    ],
    {
      filters: ['branch_id', 'is_active'],
      sortable: ['name'],
      defaultSort: 'name',
      unique: { field: 'registration', normalize: (v) => v },
      build: (body, id) => ({ id, ...body }) as never,
    },
  );
  const equipments = mockCrudApi<EquipmentRecord>('/equipments', [EXISTING], {
    searchFields: ['code', 'name', 'plate'],
    filters: ['family_id', 'branch_id', 'status'],
    sortable: ['code', 'name', 'plate', 'status', 'year', 'acquisition_date'],
    unique: {
      field: 'plate',
      normalize: (v) => v.toUpperCase().replace(/[\s-]+/g, ''),
      message: 'A placa já está em uso.',
    },
    build: (body, id, current) => {
      const merged = { ...(current ?? EXISTING), ...body, id } as EquipmentRecord;
      // Como a API: code em trim + maiúsculas.
      if (typeof body.code === 'string') merged.code = body.code.trim().toUpperCase();
      merged.branch = body.branch_id === 2 ? NORTE : MATRIZ;
      merged.cost_center =
        [CC_MATRIZ, CC_NORTE, CC_GERAL].find((c) => c.id === body.cost_center_id) ?? CC_GERAL;
      return merged;
    },
  });
  return { equipments, employees };
}

async function renderPage() {
  mockAuthApi('admin');
  mockMetaEnums();
  storeSession();
  renderApp('/ativos/equipamentos');
  await screen.findByText('CAM-001');
}

async function openCreate() {
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: 'Cadastrar Nova Frota' }));
  const dialog = await screen.findByRole('dialog', { name: 'Cadastrar Nova Frota' });
  return { user, dialog };
}

const tab = (dialog: HTMLElement, name: string) =>
  within(dialog).getByRole('tab', { name: new RegExp(`^${name}`) });

async function fillGerais(
  user: ReturnType<typeof userEvent.setup>,
  dialog: HTMLElement,
  branchId = '2',
) {
  await user.type(within(dialog).getByRole('textbox', { name: /Código/ }), 'cam-010');
  await user.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Caminhão novo');
  const family = within(dialog).getByRole('combobox', { name: /Família/ });
  await waitFor(() => expect(family).toBeEnabled());
  await user.selectOptions(family, '1');
  const branchSelect = within(dialog).getByRole('combobox', { name: /Filial/ });
  await waitFor(() => expect(branchSelect).toBeEnabled());
  await user.selectOptions(branchSelect, branchId);
}

describe('Equipamentos: formulário por abas (F1-26)', () => {
  it('abas Gerais, Financeiro e Telemetria (desabilitada, fase futura); obrigatórios com *', async () => {
    mockApis();
    await renderPage();
    const { dialog } = await openCreate();
    expect(
      within(dialog)
        .getAllByRole('tab')
        .map((t) => t.textContent),
    ).toEqual(['Gerais', 'Financeiro', 'TelemetriaEm breve']);
    expect(tab(dialog, 'Gerais')).toHaveAttribute('aria-selected', 'true');
    expect(tab(dialog, 'Telemetria')).toHaveAttribute('aria-disabled', 'true');
    const labels = [...dialog.querySelectorAll('label')].map((l) => l.textContent?.trim());
    expect(labels).toEqual(
      expect.arrayContaining([
        'Código *',
        'Nome *',
        'Família/Classe *',
        'Filial *',
        'Status *',
        'Centro de custo *',
      ]),
    );
    expect(labels).toContain('Placa');
  });

  it('cria: payload espelha a API (placa normalizada, números, nulos), toast e lista atualizada', async () => {
    const { equipments } = mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    await fillGerais(user, dialog);
    await user.type(within(dialog).getByRole('textbox', { name: /Placa/ }), 'abc-9z99');
    await user.type(within(dialog).getByRole('spinbutton', { name: /Ano/ }), '2024');
    await user.click(tab(dialog, 'Financeiro'));
    await user.selectOptions(
      within(dialog).getByRole('combobox', { name: /Centro de custo/ }),
      '9',
    );
    await user.type(
      within(dialog).getByRole('spinbutton', { name: /Valor de aquisição/ }),
      '350000.75',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(equipments.writes[0]).toEqual({
      method: 'POST',
      body: {
        code: 'cam-010',
        name: 'Caminhão novo',
        family_id: 1,
        branch_id: 2,
        cost_center_id: 9,
        responsible_employee_id: null,
        plate: 'ABC9Z99',
        serial_number: null,
        manufacturer: null,
        model: null,
        year: 2024,
        status: 'active',
        criticality_override: null,
        odometer_km: 0,
        hour_meter: 0,
        acquisition_date: null,
        acquisition_value: 350000.75,
        notes: null,
      },
    });
    expect(await screen.findByText('Equipamento cadastrado')).toBeInTheDocument();
    expect(await screen.findByText('CAM-010')).toBeInTheDocument();
  });

  it('validação no cliente impede o submit e foca o primeiro campo com erro', async () => {
    const { equipments } = mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    const code = within(dialog).getByRole('textbox', { name: /Código/ });
    await waitFor(() => expect(code).toHaveFocus());
    expect(code).toHaveAttribute('aria-invalid', 'true');
    expect(code).toHaveAccessibleDescription(new RegExp(REQUIRED));
    expect(within(dialog).getByRole('combobox', { name: /Família/ })).toHaveAccessibleDescription(
      REQUIRED,
    );
    expect(equipments.writes).toHaveLength(0);
  });

  it('erro só no Financeiro: o form vai para a aba Financeiro e foca o campo', async () => {
    const { equipments } = mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    await fillGerais(user, dialog);
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(tab(dialog, 'Financeiro')).toHaveAttribute('aria-selected', 'true'));
    const cc = within(dialog).getByRole('combobox', { name: /Centro de custo/ });
    await waitFor(() => expect(cc).toHaveFocus());
    expect(cc).toHaveAccessibleDescription(new RegExp(REQUIRED));
    expect(equipments.writes).toHaveLength(0);
  });

  it('placa e ano inválidos são barrados no cliente com a mensagem no campo', async () => {
    mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    await fillGerais(user, dialog);
    await user.type(within(dialog).getByRole('textbox', { name: /Placa/ }), 'ab-1');
    await user.type(within(dialog).getByRole('spinbutton', { name: /Ano/ }), '1900');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    const plate = within(dialog).getByRole('textbox', { name: /Placa/ });
    await waitFor(() => expect(plate).toHaveAttribute('aria-invalid', 'true'));
    expect(plate).toHaveAccessibleDescription(new RegExp(PLATE_MESSAGE.slice(0, 20)));
    expect(within(dialog).getByRole('spinbutton', { name: /Ano/ })).toHaveAccessibleDescription(
      /entre 1950 e/,
    );
  });

  it('422 do servidor: placa duplicada aparece no campo e o modal continua aberto', async () => {
    mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    await fillGerais(user, dialog);
    await user.type(within(dialog).getByRole('textbox', { name: /Placa/ }), 'abc-1d23');
    await user.click(tab(dialog, 'Financeiro'));
    await user.selectOptions(
      within(dialog).getByRole('combobox', { name: /Centro de custo/ }),
      '9',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    // O erro está na aba Gerais: o form volta para ela.
    await waitFor(() => expect(tab(dialog, 'Gerais')).toHaveAttribute('aria-selected', 'true'));
    const plate = within(dialog).getByRole('textbox', { name: /Placa/ });
    await waitFor(() => expect(plate).toHaveAttribute('aria-invalid', 'true'));
    expect(plate).toHaveAccessibleDescription(/A placa já está em uso\./);
    expect(within(dialog).getByRole('alert')).toHaveTextContent(INVALID_DATA);
  });

  it('422 de consistência no Financeiro (centro de custo) leva à aba Financeiro', async () => {
    mockApis();
    server.use(
      http.post(API('/equipments'), () =>
        fail(422, INVALID_DATA, {
          cost_center_id: ['O centro de custo precisa ser da filial do equipamento ou sem filial.'],
        }),
      ),
    );
    await renderPage();
    const { user, dialog } = await openCreate();
    await fillGerais(user, dialog);
    await user.click(tab(dialog, 'Financeiro'));
    await user.selectOptions(
      within(dialog).getByRole('combobox', { name: /Centro de custo/ }),
      '9',
    );
    await user.click(tab(dialog, 'Gerais'));
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(tab(dialog, 'Financeiro')).toHaveAttribute('aria-selected', 'true'));
    expect(
      within(dialog).getByRole('combobox', { name: /Centro de custo/ }),
    ).toHaveAccessibleDescription(/filial do equipamento/);
  });

  it('selects remotos: CC da filial + sem filial, responsável da filial; trocar a filial limpa os dois', async () => {
    const { employees } = mockApis();
    await renderPage();
    const user = userEvent.setup();
    await user.click(
      screen.getByRole('button', { name: 'Editar CAM-001 — Caminhão basculante 01' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Editar frota' });
    const responsible = within(dialog).getByRole('combobox', { name: /Responsável/ });
    await waitFor(() => expect(responsible).toHaveValue('5'));
    const lookup = employees.lastListParams();
    expect([lookup.get('branch_id'), lookup.get('is_active'), lookup.get('per_page')]).toEqual([
      '1',
      '1',
      String(LOOKUP_PER_PAGE),
    ]);

    await user.selectOptions(within(dialog).getByRole('combobox', { name: /Filial/ }), '2');
    expect(responsible).toHaveValue('');
    await waitFor(() => expect(employees.lastListParams().get('branch_id')).toBe('2'));
    await waitFor(() =>
      expect(
        within(responsible)
          .getAllByRole('option')
          .map((o) => o.textContent),
      ).toEqual(['Sem responsável', 'Pedro Norte (MAT-006)']),
    );
    await user.click(tab(dialog, 'Financeiro'));
    const cc = within(dialog).getByRole('combobox', { name: /Centro de custo/ });
    expect(cc).toHaveValue('');
    expect(
      within(cc)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Selecione', 'CC-0201 — Oficina Norte', 'CC-0900 — Corporativo']);
  });

  it('edita: campos preenchidos nas duas abas e PUT com o payload completo', async () => {
    const { equipments } = mockApis();
    await renderPage();
    const user = userEvent.setup();
    await user.click(
      screen.getByRole('button', { name: 'Editar CAM-001 — Caminhão basculante 01' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Editar frota' });
    expect(within(dialog).getByRole('textbox', { name: /Placa/ })).toHaveValue('ABC1D23');
    expect(within(dialog).getByRole('combobox', { name: /Criticidade/ })).toHaveValue('high');
    await user.click(tab(dialog, 'Financeiro'));
    expect(within(dialog).getByRole('spinbutton', { name: /Odômetro/ })).toHaveValue(125430.5);
    expect(within(dialog).getByLabelText(/Data de aquisição/)).toHaveValue('2022-03-15');
    await user.click(tab(dialog, 'Gerais'));
    const name = within(dialog).getByRole('textbox', { name: /Nome/ });
    await user.clear(name);
    await user.type(name, 'Caminhão basculante revisado');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(equipments.writes[0]).toMatchObject({
      method: 'PUT',
      id: 1,
      body: {
        name: 'Caminhão basculante revisado',
        plate: 'ABC1D23',
        cost_center_id: 1,
        responsible_employee_id: 5,
        odometer_km: 125430.5,
        acquisition_value: 450000,
        criticality_override: 'high',
      },
    });
    expect(await screen.findByText('Alterações salvas')).toBeInTheDocument();
    expect(await screen.findByText('Caminhão basculante revisado')).toBeInTheDocument();
  });

  it('teclado: setas trocam de aba (Telemetria não ativa) e Esc fecha devolvendo o foco', async () => {
    mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    tab(dialog, 'Gerais').focus();
    await user.keyboard('{ArrowRight}');
    expect(tab(dialog, 'Financeiro')).toHaveAttribute('aria-selected', 'true');
    expect(tab(dialog, 'Financeiro')).toHaveFocus();
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(screen.getByRole('button', { name: 'Cadastrar Nova Frota' })).toHaveFocus();
  });

  it('excluir: 409 mostra a mensagem do envelope sem fechar o diálogo', async () => {
    const { equipments } = mockApis();
    equipments.deleteConflicts.set(1, {
      message: 'Não é possível excluir: existem registros ativos vinculados (ordens de serviço).',
      dependents: ['work_orders'],
    });
    await renderPage();
    const user = userEvent.setup();
    await user.click(
      screen.getByRole('button', { name: 'Excluir CAM-001 — Caminhão basculante 01' }),
    );
    const confirm = await screen.findByRole('alertdialog', { name: 'Excluir equipamento?' });
    await user.click(within(confirm).getByRole('button', { name: 'Excluir' }));
    expect(await within(confirm).findByRole('alert')).toHaveTextContent('(ordens de serviço)');
    expect(screen.getByRole('alertdialog')).toBeInTheDocument();
  });

  it('alvos ≥ 48px e sem violações axe (Gerais e Financeiro)', async () => {
    mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    for (const control of [
      ...within(dialog).getAllByRole('tab'),
      ...within(dialog).getAllByRole('textbox'),
      ...within(dialog).getAllByRole('combobox'),
    ]) {
      expect(control).toHaveClass('min-h-12', 'min-w-12');
    }
    expect(await axe(dialog)).toHaveNoViolations();
    await user.click(tab(dialog, 'Financeiro'));
    expect(await axe(dialog)).toHaveNoViolations();
  });
});
