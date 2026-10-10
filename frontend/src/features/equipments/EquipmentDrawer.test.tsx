import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import type { Role } from '@/features/auth';
import { NOT_FOUND_MESSAGE } from './EquipmentDrawer';
import { formatDate } from './format';

type Ref = { id: number; code: string; name: string };
const MATRIZ: Ref = { id: 1, code: 'FIL-001', name: 'Matriz' };
const CAMINHAO: Ref = { id: 1, code: 'CAM', name: 'Caminhões' };

function equipment(id: number, code: string, name: string, over: Record<string, unknown> = {}) {
  return {
    id,
    code,
    name,
    family: CAMINHAO,
    family_id: 1,
    branch: MATRIZ,
    branch_id: 1,
    cost_center: { id: 9, code: 'CC-0900', name: 'Corporativo' },
    responsible_employee: null as null | { id: number; registration: string; name: string },
    plate: null as string | null,
    serial_number: null as string | null,
    manufacturer: null as string | null,
    model: null as string | null,
    year: null as number | null,
    status: 'active',
    criticality: 'medium',
    criticality_source: 'family',
    criticality_override: null as string | null,
    odometer_km: 0,
    hour_meter: 0,
    acquisition_date: null as string | null,
    acquisition_value: null as number | null,
    notes: null as string | null,
    created_at: '2026-10-01T12:30:00Z',
    updated_at: '2026-10-09T23:10:00Z',
    deleted_at: null as string | null,
    ...over,
  };
}
type EquipmentRecord = ReturnType<typeof equipment>;

const FULL = equipment(1, 'CAM-001', 'Caminhão basculante 01', {
  responsible_employee: { id: 5, registration: 'MAT-005', name: 'João Motorista' },
  plate: 'ABC1D23',
  serial_number: 'SN-778',
  manufacturer: 'Volvo',
  model: 'FH 540',
  year: 2022,
  criticality: 'high',
  criticality_source: 'override',
  criticality_override: 'high',
  odometer_km: 125430.5,
  hour_meter: 3210,
  acquisition_date: '2022-03-15',
  acquisition_value: 450000,
  notes: 'Revisado em setembro.\nPneus novos.',
});
const SIMPLE = equipment(2, 'TRA-001', 'Trator agrícola');
const DELETED = equipment(3, 'CAM-003', 'Caminhão vendido', { deleted_at: '2026-10-05T10:00:00Z' });

function mockApis() {
  mockCrudApi('/branches', [branch(1, MATRIZ.code, MATRIZ.name)], {
    filters: ['is_active'],
    sortable: ['code', 'name'],
    build: (b, id) => ({ ...branch(id, '', ''), ...b }),
  });
  mockCrudApi('/equipment-families', [{ ...CAMINHAO, is_active: true, deleted_at: null }], {
    filters: ['is_active'],
    sortable: ['code', 'name'],
    build: (b, id) => ({ id, ...b }) as never,
  });
  // Lookups do formulário (atalho Editar → F1-26).
  mockCrudApi(
    '/cost-centers',
    [
      {
        id: 9,
        code: 'CC-0900',
        name: 'Corporativo',
        branch: null,
        is_active: true,
        deleted_at: null,
      },
    ],
    { filters: ['is_active'], sortable: ['code'], build: (b, id) => ({ id, ...b }) as never },
  );
  mockCrudApi(
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
    ],
    {
      filters: ['branch_id', 'is_active'],
      sortable: ['name'],
      defaultSort: 'name',
      unique: { field: 'registration', normalize: (v) => v },
      build: (b, id) => ({ id, ...b }) as never,
    },
  );
  return mockCrudApi<EquipmentRecord>('/equipments', [FULL, SIMPLE, DELETED], {
    filters: ['family_id', 'branch_id', 'status'],
    sortable: ['code', 'name', 'plate', 'status', 'year', 'acquisition_date'],
    build: (body, id, current) => ({ ...(current ?? SIMPLE), ...body, id }) as EquipmentRecord,
  });
}

async function renderPage(role: Role = 'admin', path = '/ativos/equipamentos') {
  mockAuthApi(role);
  mockMetaEnums();
  storeSession();
  const view = renderApp(path);
  // `hidden`: com ?id= o drawer (modal) já abre e deixa a página aria-hidden.
  await screen.findByRole('heading', { level: 1, name: 'Frotas & Equipamentos', hidden: true });
  return view;
}

// `hidden`: com o drawer aberto (modal), a lista fica aria-hidden.
const rowButton = (code: string) =>
  within(screen.getByRole('table', { hidden: true })).getByRole('button', {
    name: code,
    hidden: true,
  });
const drawer = () => screen.findByRole('dialog', { name: /CAM-001|TRA-001|Equipamento/ });
const group = (dialog: HTMLElement, name: string) => within(dialog).getByRole('region', { name });

describe('Equipamentos: Drawer 360° (F1-27)', () => {
  it('formatDate: data sem hora em dd/mm/aaaa, sem fuso', () => {
    expect(formatDate('2022-03-15')).toBe('15/03/2022');
    expect(formatDate(null)).toBe('—');
  });

  it('clicar na linha abre o drawer com todos os campos agrupados e o ?id= na URL', async () => {
    mockApis();
    const user = userEvent.setup();
    const { location } = await renderPage();
    await user.click(await screen.findByText('Caminhão basculante 01'));
    const dialog = await drawer();
    expect(dialog).toHaveAccessibleName('CAM-001 — Caminhão basculante 01');
    expect(location()).toBe('/ativos/equipamentos?id=1');
    expect(await within(dialog).findByRole('region', { name: 'Identificação' })).toHaveTextContent(
      'CódigoCAM-001NomeCaminhão basculante 01PlacaABC1D23Nº de sérieSN-778FabricanteVolvoModeloFH 540Ano2022StatusAtivo',
    );
    expect(group(dialog, 'Classe e criticidade')).toHaveTextContent(
      'Família/ClasseCAM — CaminhõesCriticidade efetivaAltaDefinida no equipamento',
    );
    expect(group(dialog, 'Alocação')).toHaveTextContent(
      'FilialFIL-001 — MatrizCentro de custoCC-0900 — CorporativoResponsávelJoão Motorista (MAT-005)',
    );
    const acquisition = group(dialog, 'Aquisição e medidores');
    expect(acquisition).toHaveTextContent('Data de aquisição15/03/2022');
    expect(acquisition.textContent?.replace(/\s/g, ' ')).toContain('R$ 450.000,00');
    expect(acquisition).toHaveTextContent('Odômetro125.430,5 kmHorímetro3.210 h');
    expect(group(dialog, 'Observações e registro')).toHaveTextContent('Revisado em setembro.');
    expect(group(dialog, 'Observações e registro')).toHaveTextContent(
      'Atualizado em09/10/2026, 20:10',
    );
  });

  it('abas: Visão Geral habilitada; Histórico OS, Pneus e Documentos desabilitadas ("Em breve")', async () => {
    mockApis();
    await renderPage('admin', '/ativos/equipamentos?id=1');
    const dialog = await drawer();
    const tabs = await within(dialog).findAllByRole('tab');
    expect(tabs.map((t) => t.textContent)).toEqual([
      'Visão Geral',
      'Histórico OSEm breve',
      'PneusEm breve',
      'DocumentosEm breve',
    ]);
    expect(tabs[0]).toHaveAttribute('aria-selected', 'true');
    for (const tab of tabs.slice(1)) expect(tab).toHaveAttribute('aria-disabled', 'true');
  });

  it('criticidade herdada da família e campos vazios como "—"', async () => {
    mockApis();
    await renderPage('admin', '/ativos/equipamentos?id=2');
    const dialog = await screen.findByRole('dialog', { name: 'TRA-001 — Trator agrícola' });
    expect(
      await within(dialog).findByRole('region', { name: 'Classe e criticidade' }),
    ).toHaveTextContent('MédiaHerdada da família');
    expect(group(dialog, 'Identificação')).toHaveTextContent('Placa—');
    expect(group(dialog, 'Alocação')).toHaveTextContent('Responsável—');
    expect(group(dialog, 'Aquisição e medidores')).toHaveTextContent('Valor de aquisição—');
  });

  it('teclado: Enter no botão da linha abre; foco preso no drawer; Esc fecha, devolve o foco e tira o id', async () => {
    mockApis();
    const user = userEvent.setup();
    const { location } = await renderPage();
    await screen.findByText('CAM-001');
    rowButton('CAM-001').focus();
    await user.keyboard('{Enter}');
    const dialog = await drawer();
    await within(dialog).findByRole('region', { name: 'Identificação' });
    expect(dialog).toContainElement(document.activeElement as HTMLElement);
    for (let i = 0; i < 8; i += 1) {
      await user.tab();
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(rowButton('CAM-001')).toHaveFocus();
    expect(location()).toBe('/ativos/equipamentos');
  });

  it('clique fora do botão (na célula) abre; Fechar devolve o foco ao botão da linha', async () => {
    mockApis();
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByText('Trator agrícola'));
    const dialog = await screen.findByRole('dialog', { name: 'TRA-001 — Trator agrícola' });
    await user.click(within(dialog).getByRole('button', { name: 'Fechar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(rowButton('TRA-001')).toHaveFocus();
  });

  it('Editar/Excluir da linha não abrem o drawer', async () => {
    mockApis();
    const user = userEvent.setup();
    const { location } = await renderPage();
    await user.click(
      await screen.findByRole('button', { name: 'Excluir CAM-001 — Caminhão basculante 01' }),
    );
    expect(await screen.findByRole('alertdialog')).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(location()).toBe('/ativos/equipamentos');
  });

  it('deep-link: abre pelo ?id= mantendo os filtros; fechar tira só o id', async () => {
    const fake = mockApis();
    const user = userEvent.setup();
    const { location } = await renderPage('admin', '/ativos/equipamentos?status=active&id=1');
    const dialog = await drawer();
    await within(dialog).findByRole('region', { name: 'Identificação' });
    expect(fake.lastListParams().get('status')).toBe('active');
    await user.click(within(dialog).getByRole('button', { name: 'Fechar' }));
    await waitFor(() => expect(location()).toBe('/ativos/equipamentos?status=active'));
  });

  it.each([
    ['inexistente', 999],
    ['excluído', 3],
  ])('404 (%s): mensagem clara no drawer e a lista continua', async (_case, id) => {
    mockApis();
    await renderPage('admin', `/ativos/equipamentos?id=${id}`);
    const dialog = await screen.findByRole('dialog', { name: 'Equipamento' });
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(NOT_FOUND_MESSAGE);
    expect(within(dialog).queryByRole('button', { name: 'Tentar novamente' })).toBeNull();
    expect(
      within(screen.getByRole('table', { hidden: true })).getByText('CAM-001'),
    ).toBeInTheDocument();
  });

  it('atalho Editar (com manage) fecha o drawer e abre o formulário preenchido', async () => {
    mockApis();
    const user = userEvent.setup();
    const { location } = await renderPage('admin', '/ativos/equipamentos?id=1');
    const dialog = await drawer();
    await user.click(await within(dialog).findByRole('button', { name: 'Editar' }));
    const form = await screen.findByRole('dialog', { name: 'Editar frota' });
    expect(within(form).getByRole('textbox', { name: /Código/ })).toHaveValue('CAM-001');
    expect(location()).toBe('/ativos/equipamentos');
  });

  it('sem manage (operador): drawer sem o atalho Editar', async () => {
    mockApis();
    await renderPage('operator', '/ativos/equipamentos?id=1');
    const dialog = await drawer();
    await within(dialog).findByRole('region', { name: 'Identificação' });
    expect(within(dialog).queryByRole('button', { name: 'Editar' })).toBeNull();
    expect(within(dialog).getByRole('button', { name: 'Fechar' })).toBeInTheDocument();
  });

  it('alvos ≥ 48px (linha, abas, botões) e sem violações axe no drawer', async () => {
    mockApis();
    await renderPage('admin', '/ativos/equipamentos?id=1');
    const dialog = await drawer();
    await within(dialog).findByRole('region', { name: 'Identificação' });
    for (const control of [
      ...within(dialog).getAllByRole('tab'),
      ...within(dialog).getAllByRole('button'),
    ]) {
      expect(control).toHaveClass('min-h-12', 'min-w-12');
    }
    expect(rowButton('CAM-001')).toHaveClass('min-h-12', 'min-w-12');
    expect(await axe(dialog)).toHaveNoViolations();
  });
});
