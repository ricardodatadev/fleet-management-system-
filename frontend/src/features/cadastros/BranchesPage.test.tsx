import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import type { Role } from '@/features/auth';

type BranchRecord = ReturnType<typeof branch>;

const SEED: BranchRecord[] = [
  branch(1, 'FIL-001', 'Matriz', { city: 'Goiânia', state: 'GO' }),
  branch(2, 'GAR-001', 'Garagem Norte', { type: 'garagem' }),
  branch(3, 'OFI-001', 'Oficina Central', { type: 'oficina', is_active: false }),
];

function mockBranches(seed: BranchRecord[] = SEED) {
  return mockCrudApi<BranchRecord>('/branches', seed, {
    filters: ['type', 'is_active'],
    sortable: ['code', 'name', 'type', 'is_active', 'created_at', 'updated_at'],
    build: (body, id, current) => ({ ...(current ?? branch(id, '', '')), ...body }) as BranchRecord,
  });
}

async function renderPage(role: Role = 'admin', path = '/cadastros/unidades') {
  mockAuthApi(role);
  mockMetaEnums();
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1, name: 'Unidades/Filiais' });
  return view;
}

const table = () => screen.getByRole('table', { name: 'Unidades/Filiais' });
const bodyRows = () => within(table()).getAllByRole('row').slice(1);

describe('Unidades/Filiais (F1-30)', () => {
  it('lista com tipo traduzido, cidade/UF e situação; paginação server-side (per_page 15)', async () => {
    const fake = mockBranches();
    await renderPage();
    expect(await screen.findByText('Matriz')).toBeInTheDocument();
    expect(fake.lastListParams().get('page')).toBe('1');
    expect(fake.lastListParams().get('per_page')).toBe('15');
    const [matriz, garagem, oficina] = bodyRows();
    expect(matriz).toHaveTextContent('FIL-001MatrizFilialGoiânia/GOAtivo');
    expect(garagem).toHaveTextContent('Garagem');
    expect(oficina).toHaveTextContent('Inativo');
  });

  it('busca q com debounce, filtro por tipo e situação refletem na URL e na API', async () => {
    const fake = mockBranches();
    const user = userEvent.setup();
    const { location } = await renderPage();
    await screen.findByText('Matriz');

    await user.type(screen.getByRole('searchbox', { name: 'Buscar' }), 'garagem');
    await waitFor(() => expect(fake.lastListParams().get('q')).toBe('garagem'));
    // Uma requisição só para o termo inteiro (debounce de 300 ms).
    expect(fake.listRequests.filter((url) => url.searchParams.has('q'))).toHaveLength(1);
    await waitFor(() => expect(bodyRows()).toHaveLength(1));

    await user.clear(screen.getByRole('searchbox', { name: 'Buscar' }));
    await user.selectOptions(screen.getByRole('combobox', { name: 'Tipo' }), 'oficina');
    await waitFor(() => expect(fake.lastListParams().get('type')).toBe('oficina'));
    await user.selectOptions(screen.getByRole('combobox', { name: 'Situação' }), '0');
    await waitFor(() => expect(fake.lastListParams().get('is_active')).toBe('0'));
    expect(location()).toContain('type=oficina');
    expect(location()).toContain('is_active=0');
    await waitFor(() => expect(bodyRows()).toHaveLength(1));
    expect(bodyRows()[0]).toHaveTextContent('Oficina Central');
  });

  it('deep-link: filtros da URL vão para a primeira requisição', async () => {
    const fake = mockBranches();
    await renderPage('admin', '/cadastros/unidades?type=garagem&sort=-name&page=1');
    await screen.findByText('Garagem Norte');
    expect(fake.listRequests[0]?.searchParams.get('type')).toBe('garagem');
    expect(fake.listRequests[0]?.searchParams.get('sort')).toBe('-name');
  });

  it('ordenação server-side pelo cabeçalho (asc → desc) e paginação', async () => {
    const seed = Array.from({ length: 20 }, (_, i) =>
      branch(i + 1, `FIL-${String(i + 1).padStart(3, '0')}`, `Filial ${i + 1}`),
    );
    const fake = mockBranches(seed);
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Filial 1');

    await user.click(screen.getByRole('button', { name: 'Código' }));
    await waitFor(() => expect(fake.lastListParams().get('sort')).toBe('code'));
    await user.click(screen.getByRole('button', { name: 'Código' }));
    await waitFor(() => expect(fake.lastListParams().get('sort')).toBe('-code'));
    await screen.findByText('Filial 20');

    await user.click(screen.getByRole('button', { name: /Próxima/ }));
    await waitFor(() => expect(fake.lastListParams().get('page')).toBe('2'));
    expect(fake.lastListParams().get('sort')).toBe('-code');
    expect(await screen.findByText('Página 2 de 2 · 20 registros')).toBeInTheDocument();
  });

  it('excluir o único item da última página volta para a última página existente', async () => {
    const seed = Array.from({ length: 31 }, (_, i) =>
      branch(i + 1, `FIL-${String(i + 1).padStart(3, '0')}`, `Filial ${i + 1}`),
    );
    const fake = mockBranches(seed);
    const user = userEvent.setup();
    const { location } = await renderPage('admin', '/cadastros/unidades?page=3');
    await screen.findByText('Página 3 de 3 · 31 registros');

    await user.click(screen.getByRole('button', { name: 'Excluir FIL-031 — Filial 31' }));
    const dialog = await screen.findByRole('alertdialog');
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));

    expect(await screen.findByText('Página 2 de 2 · 30 registros')).toBeInTheDocument();
    expect(location()).toContain('page=2');
    expect(fake.lastListParams().get('page')).toBe('2');
    expect(screen.getByText('Filial 30')).toBeInTheDocument();
    expect(screen.queryByText('Nenhum registro cadastrado')).not.toBeInTheDocument();
  });

  it('deep-link com página inexistente (?page=99) cai na última página', async () => {
    const fake = mockBranches();
    const { location } = await renderPage('admin', '/cadastros/unidades?type=filial&page=99');
    expect(await screen.findByText('Matriz')).toBeInTheDocument();
    expect(location()).toBe('/cadastros/unidades?type=filial');
    expect(fake.lastListParams().get('page')).toBe('1');
    expect(fake.lastListParams().get('type')).toBe('filial');
    expect(screen.queryByText(/Nenhum registro|Nenhum resultado/)).not.toBeInTheDocument();
  });

  it('página inexistente sem nenhum registro vai para a 1ª e mostra o vazio real', async () => {
    mockBranches([]);
    const { location } = await renderPage('admin', '/cadastros/unidades?page=5');
    expect(await screen.findByText('Nenhum registro cadastrado')).toBeInTheDocument();
    expect(location()).toBe('/cadastros/unidades');
  });

  it('cria unidade: tipo do /meta/enums, UF, payload com nulos e lista atualizada', async () => {
    const fake = mockBranches();
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Matriz');

    await user.click(screen.getByRole('button', { name: 'Nova unidade' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nova unidade' });
    await user.type(within(dialog).getByRole('textbox', { name: /Código/ }), 'fil-009');
    await user.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Filial Sul');
    await user.selectOptions(within(dialog).getByRole('combobox', { name: /Tipo/ }), 'oficina');
    await user.selectOptions(within(dialog).getByRole('combobox', { name: 'UF' }), 'SP');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(fake.writes[0]).toEqual({
      method: 'POST',
      body: {
        code: 'fil-009',
        name: 'Filial Sul',
        type: 'oficina',
        city: null,
        state: 'SP',
        is_active: true,
      },
    });
    expect(await screen.findByText('Unidade cadastrada')).toBeInTheDocument();
    expect(await screen.findByText('Filial Sul')).toBeInTheDocument();
  });

  it('obrigatórios com asterisco e pré-checagem no cliente: erro no campo sem chamar a API', async () => {
    const fake = mockBranches();
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: 'Nova unidade' }));
    const dialog = await screen.findByRole('dialog');
    // Fora das telas públicas o asterisco continua (decisão do usuário, G.1).
    const labels = [...dialog.querySelectorAll('label')].map((l) => l.textContent?.trim());
    expect(labels).toEqual(
      expect.arrayContaining(['Código *', 'Nome *', 'Tipo *', 'Cidade', 'UF']),
    );
    expect(within(dialog).getByRole('textbox', { name: 'Código' })).toHaveAttribute(
      'aria-required',
      'true',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    expect(within(dialog).getByRole('textbox', { name: /Código/ })).toHaveAttribute(
      'aria-invalid',
      'true',
    );
    expect(within(dialog).getAllByText('Campo obrigatório.')).toHaveLength(3);
    expect(fake.writes).toHaveLength(0);
  });

  it('422: erro do servidor aparece junto ao campo e o modal continua aberto', async () => {
    mockBranches();
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: 'Nova unidade' }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByRole('textbox', { name: /Código/ }), 'FIL-001');
    await user.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Outra');
    await user.selectOptions(within(dialog).getByRole('combobox', { name: /Tipo/ }), 'filial');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    const code = within(dialog).getByRole('textbox', { name: /Código/ });
    await waitFor(() => expect(code).toHaveAttribute('aria-invalid', 'true'));
    expect(code).toHaveAccessibleDescription(/O código já está em uso\./);
    expect(within(dialog).getByRole('alert')).toHaveTextContent(
      'Os dados informados são inválidos.',
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('edita com o formulário preenchido (PUT)', async () => {
    const fake = mockBranches();
    const user = userEvent.setup();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: 'Editar FIL-001 — Matriz' }));
    const dialog = await screen.findByRole('dialog', { name: 'Editar unidade' });
    const name = within(dialog).getByRole('textbox', { name: /Nome/ });
    expect(name).toHaveValue('Matriz');
    expect(within(dialog).getByRole('combobox', { name: 'UF' })).toHaveValue('GO');
    await user.clear(name);
    await user.type(name, 'Matriz Goiânia');
    await user.click(within(dialog).getByRole('switch', { name: 'Ativo' }));
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(fake.writes[0]).toMatchObject({
      method: 'PUT',
      id: 1,
      body: { name: 'Matriz Goiânia', city: 'Goiânia', state: 'GO', is_active: false },
    });
    expect(await screen.findByText('Matriz Goiânia')).toBeInTheDocument();
  });

  it('excluir: confirmação; 409 mostra a mensagem do envelope sem fechar; depois exclui', async () => {
    const fake = mockBranches();
    fake.deleteConflicts.set(1, {
      message: 'Não é possível excluir: existem registros ativos vinculados (centros de custo).',
      dependents: ['cost_centers'],
    });
    const user = userEvent.setup();
    await renderPage();

    await user.click(await screen.findByRole('button', { name: 'Excluir FIL-001 — Matriz' }));
    let dialog = await screen.findByRole('alertdialog', { name: 'Excluir unidade?' });
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Não é possível excluir: existem registros ativos vinculados (centros de custo).',
    );
    expect(screen.getByRole('alertdialog')).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

    await user.click(screen.getByRole('button', { name: 'Excluir GAR-001 — Garagem Norte' }));
    dialog = await screen.findByRole('alertdialog');
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));
    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument());
    await waitFor(() => expect(screen.queryByText('Garagem Norte')).not.toBeInTheDocument());
    expect(screen.getByText('Registro excluído')).toBeInTheDocument();
  });

  it('restaurar: "Mostrar excluídos" envia with_trashed=1 e Restaurar reativa a linha', async () => {
    const fake = mockBranches([
      ...SEED,
      branch(4, 'FIL-004', 'Filial Antiga', { deleted_at: '2026-10-01T12:00:00Z' }),
    ]);
    const user = userEvent.setup();
    await renderPage();
    await screen.findByText('Matriz');
    expect(screen.queryByText('Filial Antiga')).not.toBeInTheDocument();

    await user.click(screen.getByRole('switch', { name: 'Mostrar excluídos' }));
    await waitFor(() => expect(fake.lastListParams().get('with_trashed')).toBe('1'));
    const row = (await screen.findByText('Filial Antiga')).closest('tr') as HTMLElement;
    expect(row).toHaveTextContent('Excluído');
    expect(within(row).queryByRole('button', { name: /Editar/ })).not.toBeInTheDocument();

    await user.click(
      within(row).getByRole('button', { name: 'Restaurar FIL-004 — Filial Antiga' }),
    );
    expect(await screen.findByText('Registro restaurado')).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.getByText('Filial Antiga').closest('tr')).toHaveTextContent('Ativo'),
    );
  });

  it('sem branches.manage: lista sem botões de escrita (ocultos) e sem with_trashed', async () => {
    const fake = mockBranches();
    await renderPage('operator', '/cadastros/unidades?with_trashed=1');
    await screen.findByText('Matriz');
    expect(screen.queryByRole('button', { name: 'Nova unidade' })).not.toBeInTheDocument();
    expect(screen.queryByRole('switch', { name: 'Mostrar excluídos' })).not.toBeInTheDocument();
    expect(screen.queryByRole('columnheader', { name: 'Ações' })).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /Editar|Excluir|Restaurar/ }),
    ).not.toBeInTheDocument();
    expect(fake.lastListParams().has('with_trashed')).toBe(false);
  });

  it('erro da API: estado de erro com a mensagem e "Tentar novamente"', async () => {
    const fake = mockBranches();
    fake.failLists({ status: 500, message: 'Erro interno do servidor.' });
    const user = userEvent.setup();
    await renderPage();
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Erro interno do servidor.');
    fake.failLists(null);
    await user.click(within(alert).getByRole('button', { name: 'Tentar novamente' }));
    expect(await screen.findByText('Matriz')).toBeInTheDocument();
  });

  it('alvos ≥ 48px nas ações de linha e no toolbar; sem violações axe (lista e formulário)', async () => {
    mockBranches();
    const user = userEvent.setup();
    const { container } = await renderPage();
    await screen.findByText('Matriz');
    for (const button of [
      screen.getByRole('button', { name: 'Editar FIL-001 — Matriz' }),
      screen.getByRole('button', { name: 'Excluir FIL-001 — Matriz' }),
      screen.getByRole('button', { name: 'Nova unidade' }),
      screen.getByRole('searchbox', { name: 'Buscar' }),
      screen.getByRole('combobox', { name: 'Tipo' }),
    ]) {
      expect(button).toHaveClass('min-h-12', 'min-w-12');
    }
    expect(await axe(container)).toHaveNoViolations();

    await user.click(screen.getByRole('button', { name: 'Nova unidade' }));
    expect(await axe(await screen.findByRole('dialog'))).toHaveNoViolations();
  });
});
