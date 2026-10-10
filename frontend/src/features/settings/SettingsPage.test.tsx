import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { http } from 'msw';
import { API, fail, mockAuthApi, ok, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi } from '@/test/crud';
import { server } from '@/test/server';
import { NO_CHANGE, SAVED, SEED, mockSettingsApi } from '@/test/settings';
import type { Role } from '@/features/auth';

const RN1 = 'Disparo da preventiva';
const RN2 = 'Bloquear fechamento de OS sem apontamento';
const RN3 = 'Permitir baixa com pendência fiscal';
const RN4 = 'Tratamento de peça ou serviço em garantia';

function mockApis(options: { leaderBranchId?: number } = {}) {
  const branches = mockCrudApi(
    '/branches',
    [branch(1, 'FIL-001', 'Matriz'), branch(2, 'FIL-002', 'Norte')],
    {
      filters: ['is_active'],
      sortable: ['code', 'name'],
      build: (b, id) => ({ ...branch(id, '', ''), ...b }),
    },
  );
  mockCrudApi(
    '/equipment-families',
    [{ id: 1, code: 'CAM', name: 'Caminhões', is_active: true, deleted_at: null }],
    {
      filters: ['is_active'],
      sortable: ['code', 'name'],
      build: (b, id) => ({ id, ...b }) as never,
    },
  );
  return { settings: mockSettingsApi(SEED, options), branches };
}

async function renderPage(role: Role = 'admin', path = '/parametros') {
  mockAuthApi(role);
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 2, name: 'Painel de Parâmetros do Gestor' });
  return view;
}

const row = (label: string) => screen.getByRole('region', { name: new RegExp(label) });
const originOf = async (label: string, text: string) =>
  waitFor(() => expect(row(label)).toHaveTextContent(text));

async function chooseScope(type: 'Global' | 'Filial' | 'Família', entity?: string) {
  const user = userEvent.setup();
  const values = { Global: 'global', Filial: 'branch', Família: 'family' } as const;
  await user.selectOptions(screen.getByRole('combobox', { name: 'Escopo' }), values[type]);
  if (entity) {
    const select = screen.getByRole('combobox', { name: type });
    await waitFor(() => expect(select).toBeEnabled());
    await user.selectOptions(select, entity);
  }
  return user;
}

describe('Painel de Parâmetros (F1-28)', () => {
  it('as 4 chaves com rótulos/RN do registry; RadioGroup no enum, Switch no bool; RN-002 provisória', async () => {
    mockApis();
    await renderPage();
    await originOf(RN4, 'Definido no global');
    for (const [label, rule] of [
      [RN1, 'RN-001'],
      [RN2, 'RN-002'],
      [RN3, 'RN-003'],
      [RN4, 'RN-004'],
    ] as const) {
      expect(row(label)).toHaveTextContent(rule);
    }
    expect(within(row(RN1)).getByRole('radiogroup', { name: RN1 })).toBeInTheDocument();
    expect(
      within(row(RN4))
        .getAllByRole('radio')
        .map((r) => r.closest('div')?.textContent),
    ).toEqual(['Apenas alertar', 'Bloquear']);
    expect(within(row(RN2)).getByRole('switch', { name: RN2 })).toBeInTheDocument();
    expect(within(row(RN3)).getByRole('switch', { name: RN3 })).toBeChecked();
    expect(row(RN2)).toHaveTextContent('Provisória');
    expect(row(RN1)).not.toHaveTextContent('Provisória');
    // Global: valores do seeder, sem "Remover" (o global não sai).
    expect(within(row(RN4)).getByRole('radio', { name: 'Apenas alertar' })).toBeChecked();
    expect(screen.queryByRole('button', { name: 'Remover override' })).toBeNull();
  });

  it('escopo Filial: chave sem escopo de filial fica indisponível; origem herdada ou definida aqui', async () => {
    const { settings } = mockApis();
    const { location } = await renderPage();
    await originOf(RN4, 'Definido no global');
    await chooseScope('Filial', '1');
    expect(location()).toBe('/parametros?escopo=filial&id=1');
    await originOf(RN3, 'Definido nesta filial');
    await originOf(RN2, 'Herdado de Global');
    await originOf(RN4, 'Herdado de Global');
    expect(row(RN1)).toHaveTextContent('Indisponível por filial');
    expect(
      within(row(RN1))
        .getAllByRole('radio')
        .every((r) => r.hasAttribute('disabled')),
    ).toBe(true);
    expect(within(row(RN1)).queryByRole('button', { name: 'Salvar' })).toBeNull();
    expect(within(row(RN3)).getByRole('button', { name: 'Remover override' })).toBeInTheDocument();
    expect(within(row(RN3)).getByRole('switch')).not.toBeChecked();
    expect(settings.effectiveRequests.some((u) => u.searchParams.get('branch_id') === '1')).toBe(
      true,
    );
  });

  it('escopo Família: valor da família e herança; RN-002/RN-003 indisponíveis', async () => {
    mockApis();
    await renderPage('admin', '/parametros?escopo=familia&id=1');
    await originOf(RN4, 'Definido nesta família');
    expect(within(row(RN4)).getByRole('radio', { name: 'Bloquear' })).toBeChecked();
    await originOf(RN1, 'Herdado de Global');
    expect(row(RN2)).toHaveTextContent('Indisponível por família');
    expect(row(RN3)).toHaveTextContent('Indisponível por família');
  });

  it('Salvar: confirmação avisa a auditoria; PUT com escopo e valor; origem passa a "Definido nesta filial"', async () => {
    const { settings } = mockApis();
    await renderPage('admin', '/parametros?escopo=filial&id=1');
    await originOf(RN2, 'Herdado de Global');
    const user = userEvent.setup();
    await user.click(within(row(RN2)).getByRole('switch'));
    await user.click(within(row(RN2)).getByRole('button', { name: 'Salvar' }));
    const dialog = await screen.findByRole('alertdialog', { name: 'Salvar parâmetro?' });
    expect(dialog).toHaveTextContent(`${RN2}: Sim em Filial FIL-001 — Matriz.`);
    expect(dialog).toHaveTextContent('A alteração fica registrada em auditoria.');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    expect(await screen.findByText(SAVED)).toBeInTheDocument();
    expect(settings.writes.at(-1)).toEqual({
      method: 'PUT',
      key: 'workorder.block_close_without_labor',
      body: { scope_type: 'branch', scope_id: 1, value: true },
    });
    await originOf(RN2, 'Definido nesta filial');
    expect(within(row(RN2)).getByRole('button', { name: 'Remover override' })).toBeInTheDocument();
  });

  it('enum no global: escolher no RadioGroup e salvar (scope_id null)', async () => {
    const { settings } = mockApis();
    await renderPage();
    await originOf(RN1, 'Definido no global');
    const user = userEvent.setup();
    const save = within(row(RN1)).getByRole('button', { name: 'Salvar' });
    expect(save).toBeDisabled(); // nada mudou ainda
    await user.click(within(row(RN1)).getByRole('radio', { name: 'Automático' }));
    expect(save).toBeEnabled();
    await user.click(save);
    await user.click(
      within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Salvar' }),
    );
    await waitFor(() =>
      expect(settings.writes.at(-1)?.body).toEqual({
        scope_type: 'global',
        scope_id: null,
        value: 'automatic',
      }),
    );
  });

  it('PUT idempotente ("nenhuma alteração"): avisa que nada mudou, sem dizer que salvou', async () => {
    mockApis();
    server.use(http.put(API('/settings/:key'), () => ok(null, NO_CHANGE)));
    await renderPage();
    await originOf(RN4, 'Definido no global');
    const user = userEvent.setup();
    await user.click(within(row(RN4)).getByRole('radio', { name: 'Bloquear' }));
    await user.click(within(row(RN4)).getByRole('button', { name: 'Salvar' }));
    await user.click(
      within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Salvar' }),
    );
    expect(await screen.findByText('Nada mudou')).toBeInTheDocument();
    expect(screen.getByText(NO_CHANGE)).toBeInTheDocument();
    expect(screen.queryByText('Parâmetro salvo')).toBeNull();
  });

  it('Remover override: DELETE com escopo na query e a origem volta a ser herdada', async () => {
    const { settings } = mockApis();
    await renderPage('admin', '/parametros?escopo=filial&id=1');
    await originOf(RN3, 'Definido nesta filial');
    const user = userEvent.setup();
    await user.click(within(row(RN3)).getByRole('button', { name: 'Remover override' }));
    const dialog = await screen.findByRole('alertdialog', {
      name: 'Remover o valor deste escopo?',
    });
    expect(dialog).toHaveTextContent('volta a herdar do nível acima');
    await user.click(within(dialog).getByRole('button', { name: 'Remover' }));
    await originOf(RN3, 'Herdado de Global');
    expect(settings.writes.at(-1)).toEqual({
      method: 'DELETE',
      key: 'stock.allow_issue_with_fiscal_pending',
      query: { scope_type: 'branch', scope_id: '1' },
    });
    expect(within(row(RN3)).queryByRole('button', { name: 'Remover override' })).toBeNull();
    expect(within(row(RN3)).getByRole('switch')).toBeChecked();
  });

  it('422 ao salvar: mensagem do envelope no diálogo, que continua aberto', async () => {
    mockApis();
    server.use(
      http.put(API('/settings/:key'), () =>
        fail(422, 'Os dados informados são inválidos.', {
          value: ['Valor inválido para este parâmetro.'],
        }),
      ),
    );
    await renderPage();
    await originOf(RN4, 'Definido no global');
    const user = userEvent.setup();
    await user.click(within(row(RN4)).getByRole('radio', { name: 'Bloquear' }));
    await user.click(within(row(RN4)).getByRole('button', { name: 'Salvar' }));
    const dialog = await screen.findByRole('alertdialog');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Os dados informados são inválidos.',
    );
    expect(screen.getByRole('alertdialog')).toBeInTheDocument();
  });

  it('403 ao consultar o efetivo: a linha mostra a mensagem', async () => {
    mockApis();
    server.use(
      http.get(API('/settings/effective'), () =>
        fail(403, 'Você não tem permissão para consultar outra filial.'),
      ),
    );
    await renderPage();
    const rn4 = await screen.findByRole('region', { name: new RegExp(RN4) });
    expect(await within(rn4).findByRole('alert')).toHaveTextContent('outra filial');
  });

  it('líder: somente leitura, só a própria filial (deep-link para outra é ignorado)', async () => {
    const { settings, branches } = mockApis({ leaderBranchId: 1 });
    await renderPage('leader', '/parametros?escopo=filial&id=2');
    expect(screen.getByText(/Somente leitura/)).toBeInTheDocument();
    await originOf(RN3, 'Definido nesta filial');
    const branchSelect = screen.getByRole('combobox', { name: 'Filial' });
    expect(branchSelect).toBeDisabled();
    expect(
      within(branchSelect)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Selecione', 'FIL-001 — Matriz']);
    expect(branchSelect).toHaveValue('1');
    expect(branches.listRequests).toHaveLength(0);
    expect(settings.effectiveRequests.every((u) => u.searchParams.get('branch_id') !== '2')).toBe(
      true,
    );
    expect(screen.queryByRole('button', { name: /Salvar|Remover/ })).toBeNull();
    expect(within(row(RN3)).getByRole('switch')).toBeDisabled();
    expect(
      within(row(RN4))
        .getAllByRole('radio')
        .every((r) => r.hasAttribute('disabled')),
    ).toBe(true);
  });

  it.each<Role>(['mechanic', 'operator'])('%s sem settings.view → /403', async (role) => {
    mockApis();
    mockAuthApi(role);
    storeSession();
    const { location } = renderApp('/parametros');
    await waitFor(() => expect(location()).toBe('/403'));
  });

  it('teclado: setas trocam a opção do RadioGroup; alvos ≥ 48px; sem violações axe', async () => {
    mockApis();
    const { container } = await renderPage();
    await originOf(RN4, 'Definido no global');
    const user = userEvent.setup();
    // Foco pelo clique na opção já marcada (fluxo real); a seta move e marca a próxima.
    await user.click(within(row(RN4)).getByRole('radio', { name: 'Apenas alertar' }));
    await user.keyboard('{ArrowDown}');
    expect(within(row(RN4)).getByRole('radio', { name: 'Bloquear' })).toHaveFocus();
    // Espaço marca a opção focada (WAI-ARIA). A seleção pela própria seta é do Radix no browser
    // (o jsdom não reproduz a ordem dos listeners); conferida no smoke real.
    await user.keyboard(' ');
    expect(within(row(RN4)).getByRole('radio', { name: 'Bloquear' })).toBeChecked();
    expect(within(row(RN4)).getByRole('button', { name: 'Salvar' })).toBeEnabled();
    for (const control of [
      ...screen.getAllByRole('radio'),
      ...screen.getAllByRole('switch'),
      ...screen.getAllByRole('button', { name: 'Salvar' }),
      screen.getByRole('combobox', { name: 'Escopo' }),
    ]) {
      expect(control).toHaveClass('min-h-12', 'min-w-12');
    }
    expect(await axe(container)).toHaveNoViolations();
  });
});
