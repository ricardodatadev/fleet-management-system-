import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { http } from 'msw';
import { API, fail, mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { auditLog, mockAuditApi } from '@/test/audit';
import { server } from '@/test/server';
import type { Role } from '@/features/auth';
import { NOT_FOUND_MESSAGE } from './AuditDrawer';
import { PERIOD_ERROR } from './AuditPage';

const SEED = [
  auditLog(1, {
    event_at: '2026-10-20T13:00:00.000000Z',
    action: 'updated',
    auditable: { type: 'cost_center', id: 7 },
    old_values: { name: 'Manutenção', branch_id: null, is_active: true },
    new_values: { name: 'Manutenção Pesada', branch_id: 2, is_active: false },
  }),
  auditLog(2, {
    event_at: '2026-10-19T02:30:00.000000Z',
    actor: { id: 12, name: 'Caio Mecânico', role: 'mechanic' },
    action: 'created',
    auditable: { type: 'equipment', id: 3 },
    old_values: null,
    new_values: { code: 'CAM-003', notes: null, plate: '' },
  }),
  auditLog(3, {
    event_at: '2026-10-18T12:00:00.000000Z',
    actor: null,
    action: 'login_failed',
    auditable: null,
    old_values: null,
    new_values: null,
    metadata: { username: 'desconhecido', reason: 'invalid_credentials' },
  }),
  auditLog(4, {
    event_at: '2026-10-17T12:00:00.000000Z',
    action: 'setting_changed',
    auditable: { type: 'setting', id: 5 },
    old_values: { value: { mode: 'manual', limits: [1, 2] } },
    new_values: { value: { mode: 'auto', limits: [] } },
    metadata: { key: 'downtime.mode', scope_type: 'branch', scope_id: 1 },
  }),
];

async function openAudit(path = '/admin/auditoria', seed = SEED) {
  mockAuthApi('admin');
  storeSession();
  const api = mockAuditApi(seed);
  const view = renderApp(path);
  // `hidden`: com o drawer aberto pelo deep-link, o Radix tira a página da árvore acessível.
  await screen.findByRole('heading', { level: 1, name: 'Auditoria', hidden: true });
  return { ...view, api };
}

const table = () => screen.getByRole('table', { name: 'Eventos de auditoria' });
const rows = () => within(table()).getAllByRole('row').slice(1);

describe('Auditoria (F2-20)', () => {
  it('lista os eventos: data em Brasília, ator com perfil, Sistema, ação, entidade e campos', async () => {
    const { api } = await openAudit();
    await screen.findByText('Centro de custo #7');
    expect(rows()).toHaveLength(4);
    const [updated, created, failed] = rows();
    expect(updated).toHaveTextContent(/20\/10\/2026.*10:00:00/);
    expect(updated).toHaveTextContent('Ana Souza (PCM/Gestor/Admin)');
    expect(updated).toHaveTextContent('Alteração');
    expect(updated).toHaveTextContent('Nome, Filial, Ativo');
    // 02:30 UTC de 19/10 ainda é 18/10 em Brasília.
    expect(created).toHaveTextContent(/18\/10\/2026.*23:30:00/);
    expect(created).toHaveTextContent('Caio Mecânico (Mecânico)');
    expect(created).toHaveTextContent('Equipamento #3');
    expect(failed).toHaveTextContent('Sistema');
    expect(failed).toHaveTextContent('Login com falha');
    expect(api.last().get('page')).toBe('1');
    expect(api.last().get('per_page')).toBe('15');
  });

  it('item Auditoria habilitado na sidebar do admin, levando a /admin/auditoria', async () => {
    await openAudit();
    const link = screen.getAllByRole('link', { name: /Auditoria/ })[0];
    expect(link).toHaveAttribute('href', '/admin/auditoria');
    expect(link).not.toHaveAttribute('aria-disabled');
  });

  it.each<Role>(['operator', 'mechanic', 'leader'])('%s vai para /403', async (role) => {
    mockAuthApi(role);
    storeSession();
    const { location } = renderApp('/admin/auditoria');
    await screen.findByRole('heading', { name: 'Acesso negado' });
    expect(location()).toBe('/403');
  });

  it('filtros de entidade, ação e período vão para a URL e para a API (dia de Brasília)', async () => {
    const { api, location } = await openAudit('/admin/auditoria?page=2');
    const user = userEvent.setup();
    await user.selectOptions(screen.getByRole('combobox', { name: 'Entidade' }), 'equipment');
    await user.selectOptions(screen.getByRole('combobox', { name: 'Ação' }), 'created');
    const filters = screen.getByRole('search', { name: 'Filtros da auditoria' });
    await user.type(within(filters).getByLabelText('De'), '2026-10-18');
    await user.type(within(filters).getByLabelText('Até'), '2026-10-18');
    await waitFor(() => expect(api.last().get('to')).toBe('2026-10-18T23:59:59.999999-03:00'));
    const sent = api.last();
    expect(sent.get('auditable_type')).toBe('equipment');
    expect(sent.get('action')).toBe('created');
    expect(sent.get('from')).toBe('2026-10-18T00:00:00-03:00');
    expect(sent.get('page')).toBe('1');
    expect(location()).toBe(
      '/admin/auditoria?auditable_type=equipment&action=created&from=2026-10-18&to=2026-10-18',
    );
    await waitFor(() => expect(rows()).toHaveLength(1));
    expect(rows()[0]).toHaveTextContent('Equipamento #3');
  });

  it('ID do registro com debounce', async () => {
    const { api, location } = await openAudit('/admin/auditoria?auditable_type=cost_center');
    await userEvent.setup().type(screen.getByRole('spinbutton', { name: 'ID do registro' }), '7');
    await waitFor(() => expect(api.last().get('auditable_id')).toBe('7'));
    expect(location()).toBe('/admin/auditoria?auditable_type=cost_center&auditable_id=7');
  });

  it('ator pelo combobox (inclui excluídos): actor_id na URL e na API', async () => {
    const { api, location } = await openAudit();
    const user = userEvent.setup();
    const input = screen.getByRole('combobox', { name: 'Ator' });
    let usersQuery: URLSearchParams | null = null;
    server.events.on('request:start', ({ request }) => {
      const url = new URL(request.url);
      if (url.pathname === API('/users')) usersQuery = url.searchParams;
    });
    await user.type(input, 'Caio');
    await user.click(await screen.findByRole('option', { name: 'Caio Mecânico (caio)' }));
    await waitFor(() => expect(api.last().get('actor_id')).toBe('12'));
    expect(location()).toBe('/admin/auditoria?actor_id=12');
    expect(input).toHaveValue('Caio Mecânico (caio)');
    expect(usersQuery!.get('with_trashed')).toBe('1');
    server.events.removeAllListeners();
    await waitFor(() => expect(rows()).toHaveLength(1));
  });

  it('deep-link com filtros preenche os controles; o ator vem de /users/{id}', async () => {
    const { api } = await openAudit(
      '/admin/auditoria?auditable_type=equipment&action=created&actor_id=12&from=2026-10-01&to=2026-10-31&page=1',
    );
    expect(screen.getByRole('combobox', { name: 'Entidade' })).toHaveValue('equipment');
    expect(screen.getByRole('combobox', { name: 'Ação' })).toHaveValue('created');
    expect(screen.getByLabelText('De')).toHaveValue('2026-10-01');
    expect(screen.getByLabelText('Até')).toHaveValue('2026-10-31');
    await waitFor(() =>
      expect(screen.getByRole('combobox', { name: 'Ator' })).toHaveValue('Caio Mecânico (caio)'),
    );
    const sent = api.last();
    expect(sent.get('actor_id')).toBe('12');
    expect(sent.get('auditable_type')).toBe('equipment');
  });

  it('ator excluído (404 em /users/{id}) usa o nome gravado no evento', async () => {
    const seed = [auditLog(9, { actor: { id: 26, name: 'Marcos Mecânico', role: 'mechanic' } })];
    await openAudit('/admin/auditoria?actor_id=26', seed);
    await waitFor(() =>
      expect(screen.getByRole('combobox', { name: 'Ator' })).toHaveValue('Marcos Mecânico'),
    );
  });

  it('ator do deep-link que não existe mais aparece como "Usuário #id"', async () => {
    await openAudit('/admin/auditoria?actor_id=99');
    await waitFor(() =>
      expect(screen.getByRole('combobox', { name: 'Ator' })).toHaveValue('Usuário #99'),
    );
    await screen.findByText('Nenhum evento para os filtros');
  });

  it('período invertido: erro no campo "Até" e nenhuma chamada à API', async () => {
    const { api } = await openAudit('/admin/auditoria?from=2026-10-20&to=2026-10-01');
    expect(screen.getByLabelText('Até')).toHaveAccessibleDescription(PERIOD_ERROR);
    expect(screen.getByLabelText('Até')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('alert')).toHaveTextContent('Corrija o período para ver os eventos.');
    expect(api.requests).toHaveLength(0);
  });

  it('erro da API aparece com "Tentar novamente"', async () => {
    mockAuthApi('admin');
    storeSession();
    server.use(http.get(API('/audit-logs'), () => fail(422, 'Filtro inválido.')));
    renderApp('/admin/auditoria');
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Filtro inválido.');
    expect(within(alert).getByRole('button', { name: 'Tentar novamente' })).toBeInTheDocument();
  });

  it('"Limpar filtros" remove todos os filtros da URL', async () => {
    const { location } = await openAudit(
      '/admin/auditoria?auditable_type=equipment&auditable_id=3&action=created',
    );
    expect(screen.getByRole('spinbutton', { name: 'ID do registro' })).toHaveValue(3);
    await userEvent.setup().click(screen.getByRole('button', { name: 'Limpar filtros' }));
    expect(location()).toBe('/admin/auditoria');
    expect(screen.getByRole('spinbutton', { name: 'ID do registro' })).toHaveValue(null);
    expect(screen.queryByRole('button', { name: 'Limpar filtros' })).not.toBeInTheDocument();
  });

  it('paginação server-side', async () => {
    const many = Array.from({ length: 20 }, (_, i) =>
      auditLog(i + 1, { event_at: `2026-10-${String(i + 1).padStart(2, '0')}T12:00:00Z` }),
    );
    const { api, location } = await openAudit('/admin/auditoria', many);
    await screen.findByText('Página 1 de 2 · 20 registros');
    await userEvent.setup().click(screen.getByRole('button', { name: /Próxima/ }));
    await waitFor(() => expect(api.last().get('page')).toBe('2'));
    expect(location()).toBe('/admin/auditoria?page=2');
    await waitFor(() => expect(rows()).toHaveLength(5));
  });

  it('linha abre o drawer (?id=) com o diff; fechar volta o foco e mantém os filtros', async () => {
    const { location } = await openAudit('/admin/auditoria?auditable_type=cost_center');
    const user = userEvent.setup();
    const open = await screen.findByRole('button', { name: /20\/10\/2026/ });
    await user.click(open);
    const drawer = await screen.findByRole('dialog', { name: 'Alteração · Centro de custo #7' });
    expect(location()).toBe('/admin/auditoria?auditable_type=cost_center&id=1');
    const changes = within(drawer).getByRole('list', { name: 'Alterações' });
    const items = within(changes).getAllByRole('listitem');
    expect(items).toHaveLength(3);
    expect(items[0]).toHaveTextContent('Nomename');
    expect(items[0]).toHaveTextContent('AntesManutenção');
    expect(items[0]).toHaveTextContent('DepoisManutenção Pesada');
    expect(items[1]).toHaveTextContent('Antesnulo');
    expect(items[1]).toHaveTextContent('Depois2');
    expect(items[2]).toHaveTextContent('AntesSim');
    expect(items[2]).toHaveTextContent('DepoisNão');
    expect(within(drawer).getByText('Sem metadados.')).toBeInTheDocument();
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(location()).toBe('/admin/auditoria?auditable_type=cost_center');
    expect(open).toHaveFocus();
  });

  it('criação: sem "antes" (— não se aplica); nulo e vazio aparecem por extenso', async () => {
    await openAudit('/admin/auditoria?id=2');
    const drawer = await screen.findByRole('dialog', { name: 'Criação · Equipamento #3' });
    const items = within(within(drawer).getByRole('list', { name: 'Alterações' })).getAllByRole(
      'listitem',
    );
    expect(items.map((item) => item.textContent)).toEqual([
      'CódigocodeAntes—não se aplicaDepoisCAM-003',
      'ObservaçõesnotesAntes—não se aplicaDepoisnulo',
      'PlacaplateAntes—não se aplicaDepoisvazio',
    ]);
  });

  it('JSON aninhado em bloco formatado; metadados listados', async () => {
    await openAudit('/admin/auditoria?id=4');
    const drawer = await screen.findByRole('dialog', { name: 'Parâmetro alterado · Parâmetro #5' });
    const blocks = drawer.querySelectorAll('pre');
    expect(blocks).toHaveLength(2);
    expect(blocks[0]?.textContent).toBe(
      JSON.stringify({ mode: 'manual', limits: [1, 2] }, null, 2),
    );
    expect(blocks[1]?.textContent).toBe(JSON.stringify({ mode: 'auto', limits: [] }, null, 2));
    const meta = within(drawer).getByRole('region', { name: 'Metadados' });
    expect(meta).toHaveTextContent('Chavekeydowntime.mode');
    expect(meta).toHaveTextContent('Escoposcope_typebranch');
  });

  it('evento sem alvo nem valores (login com falha)', async () => {
    await openAudit('/admin/auditoria?id=3');
    const drawer = await screen.findByRole('dialog', { name: 'Login com falha · —' });
    expect(within(drawer).getByText('Sem valores registrados neste evento.')).toBeInTheDocument();
    expect(within(drawer).getByRole('region', { name: 'Evento' })).toHaveTextContent('AtorSistema');
    expect(within(drawer).getByRole('region', { name: 'Metadados' })).toHaveTextContent(
      'Motivoreasoninvalid_credentials',
    );
  });

  it('deep-link para evento inexistente: mensagem de não encontrado', async () => {
    await openAudit('/admin/auditoria?id=999');
    const drawer = await screen.findByRole('dialog', { name: 'Evento de auditoria' });
    expect(await within(drawer).findByRole('alert')).toHaveTextContent(NOT_FOUND_MESSAGE);
    expect(within(drawer).queryByRole('button', { name: 'Tentar novamente' })).toBeNull();
  });

  it('falha no detalhe permite tentar de novo', async () => {
    await openAudit();
    server.use(http.get(API('/audit-logs/:id'), () => fail(500, 'Erro interno.')));
    await userEvent.setup().click(await screen.findByRole('button', { name: /20\/10\/2026/ }));
    const drawer = await screen.findByRole('dialog', { name: 'Evento de auditoria' });
    const alert = await within(drawer).findByRole('alert');
    expect(alert).toHaveTextContent('Erro interno.');
    expect(within(alert).getByRole('button', { name: 'Tentar novamente' })).toBeInTheDocument();
  });

  it('alvos de 48px: filtros, linha e Limpar filtros', async () => {
    await openAudit('/admin/auditoria?action=updated');
    await screen.findByText('Centro de custo #7');
    const filters = screen.getByRole('search', { name: 'Filtros da auditoria' });
    for (const control of [
      within(filters).getByRole('combobox', { name: 'Entidade' }),
      within(filters).getByRole('combobox', { name: 'Ator' }),
      within(filters).getByLabelText('De'),
      within(filters).getByRole('button', { name: 'Limpar filtros' }),
      screen.getByRole('button', { name: /20\/10\/2026/ }),
    ]) {
      expect(control).toHaveClass('min-h-12');
    }
  });

  it('axe: lista e drawer sem violações', async () => {
    const { container } = await openAudit();
    await screen.findByText('Centro de custo #7');
    expect(await axe(container)).toHaveNoViolations();
    await userEvent.setup().click(screen.getByRole('button', { name: /20\/10\/2026/ }));
    const drawer = await screen.findByRole('dialog', { name: /Alteração/ });
    await within(drawer).findByRole('list', { name: 'Alterações' });
    await act(async () => {
      expect(await axe(document.body)).toHaveNoViolations();
    });
  });
});
