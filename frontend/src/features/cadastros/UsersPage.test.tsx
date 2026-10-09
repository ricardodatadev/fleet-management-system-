import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { http } from 'msw';
import { API, INVALID_DATA, fail, mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import { server } from '@/test/server';
import type { FieldErrors } from '@/api';
import type { Role } from '@/features/auth';
import { SELF_ACTIVE_LOCK, SELF_ROLE_LOCK, USERNAME_HELP } from './UsersPage';

const MATRIZ = { id: 1, code: 'FIL-001', name: 'Matriz' };
const USERNAME_RULE = /^(?=.{3,30}$)[a-z0-9]+(\.[a-z0-9]+)*$/;
const LAST_ADMIN = 'Não é possível excluir, desativar ou rebaixar o último administrador ativo.';

function user(
  id: number,
  name: string,
  username: string,
  role: string,
  over: Record<string, unknown> = {},
) {
  return {
    id,
    name,
    username,
    email: `${username}@example.com`,
    role,
    branch: role === 'admin' ? null : MATRIZ,
    branch_id: role === 'admin' ? null : 1,
    is_active: true,
    last_login_at: null as string | null,
    created_at: null,
    updated_at: null,
    deleted_at: null as string | null,
    employee: null as { id: number; registration: string; name: string } | null,
    ...over,
  };
}
type UserRecord = ReturnType<typeof user>;

// O usuário logado (mockAuthApi) é o id 1, Ana Souza, admin.
const SEED: UserRecord[] = [
  user(1, 'Ana Souza', 'anasouza', 'admin', { last_login_at: '2026-10-09T13:05:00Z' }),
  user(2, 'Bruno Admin', 'bruno', 'admin'),
  user(11, 'Bia Lima', 'bia.lima', 'leader', {
    employee: { id: 3, registration: 'MAT-003', name: 'Bia Lima' },
  }),
  user(12, 'Caio Mecânico', 'caio', 'mechanic'),
];

/** Regras da D.2 (v1.3/v1.9) que a tela precisa mostrar no campo. */
function validateUser(body: Record<string, unknown>): FieldErrors | null {
  const errors: FieldErrors = {};
  if (
    typeof body.username === 'string' &&
    !USERNAME_RULE.test(body.username.trim().toLowerCase())
  ) {
    errors.username = [USERNAME_HELP];
  }
  if (body.role !== undefined && body.role !== 'admin' && !body.branch_id) {
    errors.branch_id = ['A filial é obrigatória para este perfil.'];
  }
  return Object.keys(errors).length > 0 ? errors : null;
}

function mockApis() {
  mockCrudApi('/branches', [branch(1, MATRIZ.code, MATRIZ.name)], {
    filters: ['is_active'],
    sortable: ['code', 'name'],
    build: (body, id) => ({ ...branch(id, '', ''), ...body }),
  });
  return mockCrudApi<UserRecord>('/users', SEED, {
    searchFields: ['name', 'username', 'email'],
    filters: ['role', 'branch_id', 'is_active'],
    sortable: ['name', 'username', 'email', 'role', 'created_at', 'last_login_at'],
    defaultSort: 'name',
    unique: {
      field: 'username',
      normalize: (v) => v.trim().toLowerCase(),
      message: 'O usuário já está em uso.',
    },
    validate: validateUser,
    build: (body, id, current) => {
      // A API nunca devolve a senha.
      const rest = { ...body };
      delete rest.password;
      const merged = { ...(current ?? user(id, '', '', 'operator')), ...rest } as UserRecord;
      if ('branch_id' in body) merged.branch = body.branch_id === 1 ? MATRIZ : null;
      return merged;
    },
  });
}

async function renderPage(role: Role = 'admin', path = '/admin/usuarios') {
  mockAuthApi(role);
  mockMetaEnums();
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1, name: 'Usuários' });
  return view;
}

const rowOf = (username: string) =>
  within(screen.getByRole('table')).getByText(username).closest('tr') as HTMLElement;

async function openCreate() {
  const ue = userEvent.setup();
  await ue.click(await screen.findByRole('button', { name: 'Novo usuário' }));
  return { ue, dialog: await screen.findByRole('dialog', { name: 'Novo usuário' }) };
}

describe('Usuários (F1-31)', () => {
  it('lista com usuário, perfil, filial, colaborador vinculado, último acesso e "Você"', async () => {
    mockApis();
    await renderPage();
    await screen.findByText('bia.lima');
    expect(rowOf('anasouza')).toHaveTextContent('Ana SouzaVocê');
    expect(rowOf('anasouza')).toHaveTextContent('PCM/Gestor/AdminTodas—09/10/2026, 10:05');
    expect(rowOf('bia.lima')).toHaveTextContent(
      'Líder/PlantonistaFIL-001 — MatrizMAT-003 — Bia Lima',
    );
    expect(rowOf('caio')).toHaveTextContent('Mecânico');
  });

  it('regras de proteção exibidas: descrição da tela e sem excluir na própria linha', async () => {
    mockApis();
    await renderPage();
    await screen.findByText('bia.lima');
    expect(
      screen.getByText(/não pode excluir, desativar nem mudar o perfil da própria conta/),
    ).toBeInTheDocument();
    expect(screen.getByText(/ao menos um administrador ativo/)).toBeInTheDocument();
    expect(within(rowOf('anasouza')).queryByRole('button', { name: /Excluir/ })).toBeNull();
    expect(within(rowOf('anasouza')).getByRole('button', { name: /Editar/ })).toBeInTheDocument();
    expect(within(rowOf('bruno')).getByRole('button', { name: /Excluir/ })).toBeInTheDocument();
  });

  it('editar a própria conta: perfil e ativo travados com o motivo e fora do payload', async () => {
    const fake = mockApis();
    const ue = userEvent.setup();
    await renderPage();
    await ue.click(await screen.findByRole('button', { name: 'Editar Ana Souza (anasouza)' }));
    const dialog = await screen.findByRole('dialog', { name: 'Editar usuário' });
    const role = within(dialog).getByRole('combobox', { name: /Perfil/ });
    expect(role).toBeDisabled();
    expect(role).toHaveAccessibleDescription(SELF_ROLE_LOCK);
    expect(within(dialog).getByRole('switch', { name: 'Ativo' })).toBeDisabled();
    expect(within(dialog).getByText(SELF_ACTIVE_LOCK)).toBeInTheDocument();
    await ue.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(fake.writes[0]?.body).not.toHaveProperty('role');
    expect(fake.writes[0]?.body).not.toHaveProperty('is_active');
    // Senha em branco na edição: mantém a atual (não vai no payload).
    expect(fake.writes[0]?.body).not.toHaveProperty('password');
  });

  it('criar: ajuda do username, senha obrigatória e filial obrigatória para não-admin', async () => {
    const fake = mockApis();
    await renderPage();
    const { ue, dialog } = await openCreate();
    const username = within(dialog).getByRole('textbox', { name: /Usuário/ });
    expect(username).toHaveAccessibleDescription(USERNAME_HELP);
    const labels = () => [...dialog.querySelectorAll('label')].map((l) => l.textContent?.trim());
    expect(labels()).toEqual(
      expect.arrayContaining(['Nome *', 'Usuário *', 'E-mail *', 'Senha *', 'Perfil *']),
    );
    // Filial só fica obrigatória quando o perfil não é admin.
    expect(labels()).toContain('Filial');
    await ue.selectOptions(within(dialog).getByRole('combobox', { name: /Perfil/ }), 'leader');
    expect(labels()).toContain('Filial *');

    await ue.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Duda Costa');
    await ue.type(username, 'duda.costa');
    await ue.type(within(dialog).getByRole('textbox', { name: /E-mail/ }), 'duda@example.com');
    await ue.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    // Pré-checagem: senha e filial vazias barram antes da API.
    expect(within(dialog).getByLabelText(/^Senha/)).toHaveAccessibleDescription(
      /Campo obrigatório\./,
    );
    expect(within(dialog).getByRole('combobox', { name: /Filial/ })).toHaveAccessibleDescription(
      /Campo obrigatório\./,
    );
    expect(fake.writes).toHaveLength(0);

    await ue.type(within(dialog).getByLabelText(/^Senha/), 'SenhaForte123');
    await ue.selectOptions(within(dialog).getByRole('combobox', { name: /Filial/ }), '1');
    await ue.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(fake.writes[0]?.body).toEqual({
      name: 'Duda Costa',
      username: 'duda.costa',
      email: 'duda@example.com',
      password: 'SenhaForte123',
      role: 'leader',
      branch_id: 1,
      is_active: true,
    });
    expect(await screen.findByText('duda.costa')).toBeInTheDocument();
  });

  it('422 do padrão do username (v1.9) aparece no campo', async () => {
    mockApis();
    await renderPage();
    const { ue, dialog } = await openCreate();
    await ue.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Edu');
    const username = within(dialog).getByRole('textbox', { name: /Usuário/ });
    await ue.type(username, 'edu..silva');
    await ue.type(within(dialog).getByRole('textbox', { name: /E-mail/ }), 'edu@example.com');
    await ue.type(within(dialog).getByLabelText(/^Senha/), 'SenhaForte123');
    await ue.selectOptions(within(dialog).getByRole('combobox', { name: /Perfil/ }), 'admin');
    await ue.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(username).toHaveAttribute('aria-invalid', 'true'));
    expect(username).toHaveAccessibleDescription(`${USERNAME_HELP} ${USERNAME_HELP}`);
    expect(within(dialog).getByRole('alert')).toHaveTextContent(INVALID_DATA);
  });

  it('409 do último admin: na exclusão (diálogo) e ao rebaixar (topo do formulário)', async () => {
    const fake = mockApis();
    fake.deleteConflicts.set(2, { message: LAST_ADMIN, dependents: [] });
    const ue = userEvent.setup();
    await renderPage();
    await ue.click(await screen.findByRole('button', { name: 'Excluir Bruno Admin (bruno)' }));
    const confirm = await screen.findByRole('alertdialog', { name: 'Excluir usuário?' });
    await ue.click(within(confirm).getByRole('button', { name: 'Excluir' }));
    expect(await within(confirm).findByRole('alert')).toHaveTextContent(LAST_ADMIN);
    await ue.click(within(confirm).getByRole('button', { name: 'Cancelar' }));

    server.use(http.put(API('/users/2'), () => fail(409, LAST_ADMIN)));
    await ue.click(screen.getByRole('button', { name: 'Editar Bruno Admin (bruno)' }));
    const dialog = await screen.findByRole('dialog', { name: 'Editar usuário' });
    await ue.selectOptions(within(dialog).getByRole('combobox', { name: /Perfil/ }), 'mechanic');
    await ue.selectOptions(within(dialog).getByRole('combobox', { name: /Filial/ }), '1');
    await ue.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(LAST_ADMIN);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it.each<Role>(['leader', 'mechanic', 'operator'])('%s sem users.view → /403', async (role) => {
    const fake = mockApis();
    mockAuthApi(role);
    storeSession();
    const { location } = renderApp('/admin/usuarios');
    await waitFor(() => expect(location()).toBe('/403'));
    expect(fake.listRequests).toHaveLength(0);
  });

  it('sem violações axe (lista e formulário); alvos ≥ 48px nas ações', async () => {
    mockApis();
    const { container } = await renderPage();
    await screen.findByText('bia.lima');
    for (const button of within(rowOf('bruno')).getAllByRole('button')) {
      expect(button).toHaveClass('min-h-12', 'min-w-12');
    }
    expect(await axe(container)).toHaveNoViolations();
    const { dialog } = await openCreate();
    expect(await axe(dialog)).toHaveNoViolations();
  });
});
