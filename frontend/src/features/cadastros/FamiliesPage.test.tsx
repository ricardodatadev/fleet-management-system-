import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { mockCrudApi, mockMetaEnums } from '@/test/crud';
import type { Role } from '@/features/auth';
import type { EquipmentFamily } from './types';

/** Contrato C.4/D.2 combinado com a F1-13 (API ainda não está no develop). */
function family(id: number, code: string, name: string, over: Partial<EquipmentFamily> = {}) {
  return {
    id,
    code,
    name,
    category: 'truck',
    criticality: 'medium',
    preventive_lead_pct: 90,
    tolerance_km: null,
    tolerance_hours: null,
    tolerance_days: null,
    is_active: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    ...over,
  } satisfies EquipmentFamily as EquipmentFamily;
}

function mockFamilies() {
  return mockCrudApi<EquipmentFamily>(
    '/equipment-families',
    [
      family(1, 'CAM-PES', 'Caminhão pesado', {
        criticality: 'high',
        preventive_lead_pct: 92.5,
        tolerance_km: 500,
        tolerance_days: 7,
      }),
      family(2, 'VEI-LEV', 'Veículo leve', { category: 'light_vehicle', criticality: 'low' }),
      family(3, 'TRA-AGR', 'Trator', { category: 'agri_machine', criticality: 'critical' }),
    ],
    {
      filters: ['category', 'criticality', 'is_active'],
      sortable: ['code', 'name', 'category', 'criticality'],
      build: (body, id, current) =>
        ({ ...(current ?? family(id, '', '')), ...body }) as EquipmentFamily,
      // Regras da F1-13: (0, 100] e no máximo 2 casas decimais (numeric(5,2)).
      validate: (body) => {
        const pct = body.preventive_lead_pct;
        if (typeof pct !== 'number') return null;
        if (pct <= 0 || pct > 100) {
          return { preventive_lead_pct: ['O pré-alerta deve ser maior que 0 e no máximo 100.'] };
        }
        return Math.round(pct * 100) !== pct * 100
          ? { preventive_lead_pct: ['O pré-alerta aceita no máximo 2 casas decimais.'] }
          : null;
      },
    },
  );
}

async function renderPage(role: Role = 'admin', path = '/cadastros/familias') {
  mockAuthApi(role);
  mockMetaEnums();
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1, name: 'Famílias/Classes' });
  return view;
}

describe('Famílias/Classes (F1-30, contrato C.4 via MSW)', () => {
  it('lista com categoria, criticidade, pré-alerta e tolerâncias formatados', async () => {
    mockFamilies();
    await renderPage();
    const row = (await screen.findByText('Caminhão pesado')).closest('tr') as HTMLElement;
    expect(row).toHaveTextContent('CaminhãoAlta92,5%500 km · 7 diasAtivo');
    expect(screen.getByText('Trator').closest('tr')).toHaveTextContent(
      'Máquina agrícolaCrítica90%—',
    );
  });

  it('ordena só pela whitelist da API (code, name, category, criticality)', async () => {
    const fake = mockFamilies();
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Trator');
    const headers = within(screen.getByRole('table')).getAllByRole('columnheader');
    const sortable = headers.filter((th) => th.hasAttribute('aria-sort'));
    expect(sortable.map((th) => th.textContent)).toEqual([
      'Código',
      'Nome',
      'Categoria',
      'Criticidade',
    ]);
    expect(
      within(screen.getByRole('columnheader', { name: 'Situação' })).queryByRole('button'),
    ).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Criticidade' }));
    await waitFor(() => expect(fake.lastListParams().get('sort')).toBe('criticality'));
    // A whitelist do MSW devolveria 422 (estado de erro) para um campo fora dela.
    expect(await screen.findByText('Trator')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('filtros de categoria e criticidade vêm do /meta/enums e vão para a API', async () => {
    const fake = mockFamilies();
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Trator');
    const category = screen.getByRole('combobox', { name: 'Categoria' });
    await waitFor(() => expect(category).toBeEnabled());
    expect(
      within(category)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Todos', 'Veículo leve', 'Caminhão', 'Máquina agrícola', 'Implemento', 'Apoio']);
    await user.selectOptions(category, 'truck');
    await user.selectOptions(screen.getByRole('combobox', { name: 'Criticidade' }), 'high');
    await waitFor(() => {
      expect(fake.lastListParams().get('category')).toBe('truck');
      expect(fake.lastListParams().get('criticality')).toBe('high');
    });
    await waitFor(() => expect(screen.queryByText('Trator')).not.toBeInTheDocument());
    expect(screen.getByText('Caminhão pesado')).toBeInTheDocument();
  });

  it('cria com os defaults (média, 90%) e tolerâncias numéricas ou nulas', async () => {
    const fake = mockFamilies();
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Trator');
    await user.click(screen.getByRole('button', { name: 'Nova família' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nova família' });
    expect(within(dialog).getByRole('combobox', { name: /Criticidade/ })).toHaveValue('medium');
    expect(within(dialog).getByRole('spinbutton', { name: /Pré-alerta/ })).toHaveValue(90);

    await user.type(within(dialog).getByRole('textbox', { name: /Código/ }), 'IMP-GRA');
    await user.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Grade aradora');
    await user.selectOptions(
      within(dialog).getByRole('combobox', { name: /Categoria/ }),
      'implement',
    );
    await user.type(within(dialog).getByRole('spinbutton', { name: 'Tolerância (horas)' }), '25');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(fake.writes[0]?.body).toEqual({
      code: 'IMP-GRA',
      name: 'Grade aradora',
      category: 'implement',
      criticality: 'medium',
      preventive_lead_pct: 90,
      tolerance_km: null,
      tolerance_hours: 25,
      tolerance_days: null,
      is_active: true,
    });
    expect(await screen.findByText('Grade aradora')).toBeInTheDocument();
  });

  it('422 de preventive_lead_pct fora de (0, 100] aparece no campo', async () => {
    mockFamilies();
    const user = userEvent.setup();
    await renderPage();
    await user.click(
      await screen.findByRole('button', { name: 'Editar CAM-PES — Caminhão pesado' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Editar família' });
    const pct = within(dialog).getByRole('spinbutton', { name: /Pré-alerta/ });
    expect(pct).toHaveValue(92.5);
    await user.clear(pct);
    await user.type(pct, '150');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(pct).toHaveAttribute('aria-invalid', 'true'));
    expect(pct).toHaveAccessibleDescription(/maior que 0 e no máximo 100/);

    // numeric(5,2): o campo sugere passo de 0,01 e o servidor recusa a 3ª casa decimal.
    expect(pct).toHaveAttribute('step', '0.01');
    await user.clear(pct);
    await user.type(pct, '92.555');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(pct).toHaveAccessibleDescription(/no máximo 2 casas decimais/));
  });

  it('excluir família com equipamentos: 409 com a mensagem do envelope', async () => {
    const fake = mockFamilies();
    fake.deleteConflicts.set(3, {
      message: 'Não é possível excluir: existem registros ativos vinculados (equipamentos).',
      dependents: ['equipments'],
    });
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: 'Excluir TRA-AGR — Trator' }));
    const dialog = await screen.findByRole('alertdialog', { name: 'Excluir família?' });
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('(equipamentos)');
  });

  it('mecânico vê (equipment_families.view) sem botões de escrita', async () => {
    mockFamilies();
    await renderPage('mechanic');
    await screen.findByText('Trator');
    expect(screen.queryByRole('button', { name: 'Nova família' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Editar|Excluir/ })).not.toBeInTheDocument();
  });

  it('operador sem equipment_families.view → /403', async () => {
    const fake = mockFamilies();
    mockAuthApi('operator');
    storeSession();
    const { location } = renderApp('/cadastros/familias');
    await waitFor(() => expect(location()).toBe('/403'));
    expect(fake.listRequests).toHaveLength(0);
  });

  it('sem violações axe (lista e formulário)', async () => {
    mockFamilies();
    const user = userEvent.setup();
    const { container } = await renderPage();
    await screen.findByText('Trator');
    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Nova família' }));
    expect(await axe(await screen.findByRole('dialog'))).toHaveNoViolations();
  });
});
