import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import type { FieldErrors } from '@/api';
import type { Role } from '@/features/auth';
import { LOOKUP_PER_PAGE } from './lookups';

type Ref = { id: number; code: string; name: string };
const MATRIZ: Ref = { id: 1, code: 'FIL-001', name: 'Matriz' };
const NORTE: Ref = { id: 2, code: 'FIL-002', name: 'Norte' };
const CC_MATRIZ: Ref = { id: 1, code: 'CC-0101', name: 'Oficina Matriz' };
const CC_NORTE: Ref = { id: 2, code: 'CC-0201', name: 'Oficina Norte' };
const CC_GERAL: Ref = { id: 9, code: 'CC-0900', name: 'Corporativo' };

/** Usuários do lookup: Carlos livre (Matriz); Bia já vinculada à colaboradora MAT-003. */
const USERS = [
  {
    id: 10,
    name: 'Carlos Dias',
    username: 'carlos',
    email: 'carlos@example.com',
    role: 'operator',
    branch: MATRIZ,
    branch_id: 1,
    is_active: true,
    last_login_at: null,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    employee: null,
  },
  {
    id: 11,
    name: 'Bia Lima',
    username: 'bia.lima',
    email: 'bia@example.com',
    role: 'leader',
    branch: MATRIZ,
    branch_id: 1,
    is_active: true,
    last_login_at: null,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    employee: { id: 3, registration: 'MAT-003', name: 'Bia Lima' },
  },
];
const userRef = (id: number) => {
  const user = USERS.find((u) => u.id === id);
  return user ? { id: user.id, name: user.name, email: user.email } : null;
};

function employee(
  id: number,
  registration: string,
  name: string,
  jobType: string,
  branchRef: Ref,
  over: Record<string, unknown> = {},
) {
  return {
    id,
    registration,
    name,
    job_type: jobType,
    branch: branchRef,
    branch_id: branchRef.id,
    cost_center: null as Ref | null,
    user: null as { id: number; name: string; email: string } | null,
    phone: null as string | null,
    hired_at: null as string | null,
    cnh_number: null as string | null,
    cnh_category: null as string | null,
    cnh_expires_at: null as string | null,
    specialty: null as string | null,
    hourly_cost: null as number | null,
    is_active: true,
    created_at: null,
    updated_at: null,
    deleted_at: null as string | null,
    ...over,
  };
}
type EmployeeRecord = ReturnType<typeof employee>;

const SEED: EmployeeRecord[] = [
  employee(1, 'MAT-001', 'João Motorista', 'driver', MATRIZ, {
    cnh_number: '12345678900',
    cnh_category: 'E',
    cnh_expires_at: '2027-05-10',
    cost_center: CC_MATRIZ,
  }),
  employee(2, 'MAT-002', 'Pedro Mecânico', 'mechanic', NORTE, {
    specialty: 'Motor diesel',
    hourly_cost: 85.5,
    cost_center: CC_NORTE,
  }),
  employee(3, 'MAT-003', 'Bia Lima', 'leader', MATRIZ, { user: userRef(11) }),
  employee(4, 'MAT-004', 'Rui Souza', 'admin_staff', NORTE),
];

const ONLY_FOR = {
  cnh_number: 'driver',
  cnh_category: 'driver',
  cnh_expires_at: 'driver',
  specialty: 'mechanic',
  hourly_cost: 'mechanic',
} as const;

/** Regras do contrato v1.5 que a tela precisa mostrar no campo (422). */
function validateEmployee(body: Record<string, unknown>): FieldErrors | null {
  const errors: FieldErrors = {};
  for (const [field, jobType] of Object.entries(ONLY_FOR)) {
    if (body[field] !== undefined && body[field] !== null && body.job_type !== jobType) {
      errors[field] = ['Este campo não vale para o tipo de colaborador escolhido.'];
    }
  }
  const cc = [CC_MATRIZ, CC_NORTE, CC_GERAL].find((c) => c.id === body.cost_center_id);
  const ccBranch = cc === CC_MATRIZ ? 1 : cc === CC_NORTE ? 2 : null;
  if (cc && ccBranch !== null && ccBranch !== body.branch_id) {
    errors.cost_center_id = [
      'O centro de custo precisa ser da filial do colaborador ou sem filial.',
    ];
  }
  const user = USERS.find((u) => u.id === body.user_id);
  if (user && user.branch_id !== body.branch_id) {
    errors.user_id = ['O usuário vinculado precisa ser da mesma filial do colaborador.'];
  }
  return Object.keys(errors).length > 0 ? errors : null;
}

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
  const costCenters = mockCrudApi(
    '/cost-centers',
    [
      { ...CC_MATRIZ, branch: MATRIZ, is_active: true, deleted_at: null },
      { ...CC_NORTE, branch: NORTE, is_active: true, deleted_at: null },
      { ...CC_GERAL, branch: null, is_active: true, deleted_at: null },
    ],
    { filters: ['is_active'], sortable: ['code'], build: (body, id) => ({ id, ...body }) as never },
  );
  const users = mockCrudApi('/users', USERS, {
    filters: ['is_active'],
    sortable: ['name'],
    defaultSort: 'name',
    unique: { field: 'username', normalize: (v) => v.trim().toLowerCase() },
    build: (body, id) => ({ id, ...body }) as never,
  });
  const refs = [CC_MATRIZ, CC_NORTE, CC_GERAL];
  const employees = mockCrudApi<EmployeeRecord>('/employees', SEED, {
    searchFields: ['name', 'registration'],
    filters: ['job_type', 'branch_id', 'is_active'],
    sortable: ['name', 'registration', 'job_type', 'hired_at'],
    defaultSort: 'name',
    unique: {
      field: 'registration',
      normalize: (v) => v.trim().toUpperCase(),
      message: 'A matrícula já está em uso.',
    },
    validate: validateEmployee,
    build: (body, id, current) => {
      const merged = {
        ...(current ?? employee(id, '', '', 'driver', MATRIZ)),
        ...body,
      } as EmployeeRecord;
      if ('branch_id' in body) merged.branch = body.branch_id === 2 ? NORTE : MATRIZ;
      if ('cost_center_id' in body)
        merged.cost_center = refs.find((r) => r.id === body.cost_center_id) ?? null;
      if ('user_id' in body) merged.user = userRef(Number(body.user_id));
      return merged;
    },
  });
  return { employees, users, costCenters };
}

async function renderPage(role: Role = 'admin', path = '/cadastros/colaboradores') {
  mockAuthApi(role);
  mockMetaEnums();
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1, name: 'Pessoas & Colaboradores' });
  return view;
}

const rows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1);
const rowOf = (text: string) => screen.getByText(text).closest('tr') as HTMLElement;

async function openCreate() {
  const user = userEvent.setup();
  await user.click(await screen.findByRole('button', { name: 'Novo colaborador' }));
  const dialog = await screen.findByRole('dialog', { name: 'Novo colaborador' });
  return { user, dialog };
}

describe('Pessoas & Colaboradores (F1-31)', () => {
  it('lista com tipo, filial, centro de custo e o usuário vinculado', async () => {
    mockApis();
    await renderPage();
    await screen.findByText('João Motorista');
    expect(rowOf('João Motorista')).toHaveTextContent(
      'MAT-001João MotoristaMotoristaFIL-001 — MatrizCC-0101—Ativo',
    );
    // Colaboradora e usuária vinculada com o mesmo nome: o vínculo aparece na própria linha.
    expect(rowOf('MAT-003')).toHaveTextContent(
      'MAT-003Bia LimaLíderFIL-001 — Matriz—Bia LimaAtivo',
    );
    expect(rowOf('Rui Souza')).toHaveTextContent('Equipe administrativa');
  });

  it('abas Todos/Motoristas/Mecânicos/Equipe Adm filtram por job_type (Equipe Adm = líder + administrativa)', async () => {
    const { employees } = mockApis();
    const user = userEvent.setup();
    const { location } = await renderPage();
    await screen.findByText('João Motorista');
    const tabs = screen.getByRole('tablist', { name: 'Tipo de colaborador' });
    expect(
      within(tabs)
        .getAllByRole('tab')
        .map((t) => t.textContent),
    ).toEqual(['Todos', 'Motoristas', 'Mecânicos', 'Equipe Adm']);
    expect(within(tabs).getByRole('tab', { name: 'Todos' })).toHaveAttribute(
      'aria-selected',
      'true',
    );

    await user.click(within(tabs).getByRole('tab', { name: 'Motoristas' }));
    await waitFor(() => expect(employees.lastListParams().get('job_type')).toBe('driver'));
    await waitFor(() => expect(rows()).toHaveLength(1));
    expect(rows()[0]).toHaveTextContent('João Motorista');

    await user.click(within(tabs).getByRole('tab', { name: 'Equipe Adm' }));
    await waitFor(() =>
      expect(employees.lastListParams().get('job_type')).toBe('leader,admin_staff'),
    );
    await waitFor(() => expect(rows()).toHaveLength(2));
    expect(location()).toContain('job_type=leader%2Cadmin_staff');

    // Volta para "Todos": a lista sem job_type já está em cache (staleTime), então vale a URL.
    await user.click(within(tabs).getByRole('tab', { name: 'Todos' }));
    await waitFor(() => expect(location()).not.toContain('job_type'));
    await waitFor(() => expect(rows()).toHaveLength(4));
  });

  it('criar motorista: CNH só aparece para motorista; centro de custo da filial; usuários livres', async () => {
    const { employees, users, costCenters } = mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    // Lookups com per_page 200 e is_active=1.
    for (const fake of [users, costCenters]) {
      const params = fake.lastListParams();
      expect(Number(params.get('per_page'))).toBe(LOOKUP_PER_PAGE);
      expect(params.get('is_active')).toBe('1');
    }
    expect(within(dialog).queryByLabelText(/Número da CNH/)).toBeNull();
    expect(within(dialog).queryByLabelText(/Especialidade/)).toBeNull();

    await user.type(within(dialog).getByRole('textbox', { name: /Matrícula/ }), 'mat-010');
    await user.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Ana Motorista');
    await user.selectOptions(within(dialog).getByRole('combobox', { name: /Tipo/ }), 'driver');
    expect(within(dialog).getByLabelText(/Número da CNH/)).toBeInTheDocument();
    expect(within(dialog).queryByLabelText(/Especialidade/)).toBeNull();

    const branchSelect = within(dialog).getByRole('combobox', { name: /Filial/ });
    await waitFor(() => expect(branchSelect).toBeEnabled());
    await user.selectOptions(branchSelect, '1');
    const cc = within(dialog).getByRole('combobox', { name: /Centro de custo/ });
    expect(
      within(cc)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Sem centro de custo', 'CC-0101 — Oficina Matriz', 'CC-0900 — Corporativo']);
    await user.selectOptions(cc, '1');
    const linked = within(dialog).getByRole('combobox', { name: /Usuário vinculado/ });
    expect(
      within(linked)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Sem acesso ao sistema', 'Carlos Dias (carlos)']);
    await user.selectOptions(linked, '10');
    await user.type(within(dialog).getByLabelText(/Número da CNH/), '99887766554');
    await user.selectOptions(
      within(dialog).getByRole('combobox', { name: /Categoria da CNH/ }),
      'D',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(employees.writes[0]?.body).toEqual({
      registration: 'mat-010',
      name: 'Ana Motorista',
      job_type: 'driver',
      branch_id: 1,
      cost_center_id: 1,
      user_id: 10,
      phone: null,
      hired_at: null,
      cnh_number: '99887766554',
      cnh_category: 'D',
      cnh_expires_at: null,
      specialty: null,
      hourly_cost: null,
      is_active: true,
    });
    expect(await screen.findByText('Ana Motorista')).toBeInTheDocument();
    expect(rowOf('Ana Motorista')).toHaveTextContent('Carlos Dias');
  });

  it('trocar o tipo ao editar manda null nos campos do tipo anterior', async () => {
    const { employees } = mockApis();
    const user = userEvent.setup();
    await renderPage();
    await user.click(
      await screen.findByRole('button', { name: 'Editar MAT-002 — Pedro Mecânico' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Editar colaborador' });
    expect(within(dialog).getByRole('textbox', { name: /Especialidade/ })).toHaveValue(
      'Motor diesel',
    );
    expect(within(dialog).getByRole('spinbutton', { name: /Custo por hora/ })).toHaveValue(85.5);

    await user.selectOptions(within(dialog).getByRole('combobox', { name: /Tipo/ }), 'driver');
    expect(within(dialog).queryByLabelText(/Especialidade/)).toBeNull();
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(employees.writes[0]).toMatchObject({
      method: 'PUT',
      id: 2,
      body: { job_type: 'driver', specialty: null, hourly_cost: null },
    });
  });

  it('trocar a filial limpa o centro de custo (que depende dela)', async () => {
    mockApis();
    const user = userEvent.setup();
    await renderPage();
    await user.click(
      await screen.findByRole('button', { name: 'Editar MAT-001 — João Motorista' }),
    );
    const dialog = await screen.findByRole('dialog');
    const cc = within(dialog).getByRole('combobox', { name: /Centro de custo/ });
    await waitFor(() => expect(cc).toHaveValue('1'));
    await user.selectOptions(within(dialog).getByRole('combobox', { name: /Filial/ }), '2');
    expect(cc).toHaveValue('');
    expect(
      within(cc)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Sem centro de custo', 'CC-0201 — Oficina Norte', 'CC-0900 — Corporativo']);
  });

  it('422 do backend nos campos: matrícula duplicada e usuário de outra filial', async () => {
    mockApis();
    await renderPage();
    const { user, dialog } = await openCreate();
    await user.type(within(dialog).getByRole('textbox', { name: /Matrícula/ }), 'MAT-001');
    await user.type(within(dialog).getByRole('textbox', { name: /Nome/ }), 'Outro');
    await user.selectOptions(within(dialog).getByRole('combobox', { name: /Tipo/ }), 'leader');
    const branchSelect = within(dialog).getByRole('combobox', { name: /Filial/ });
    await waitFor(() => expect(branchSelect).toBeEnabled());
    await user.selectOptions(branchSelect, '2');
    await user.selectOptions(
      within(dialog).getByRole('combobox', { name: /Usuário vinculado/ }),
      '10',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    const registration = within(dialog).getByRole('textbox', { name: /Matrícula/ });
    await waitFor(() => expect(registration).toHaveAttribute('aria-invalid', 'true'));
    expect(registration).toHaveAccessibleDescription(/A matrícula já está em uso\./);
    expect(
      within(dialog).getByRole('combobox', { name: /Usuário vinculado/ }),
    ).toHaveAccessibleDescription(/mesma filial do colaborador/);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('excluir com dependentes: 409 com a mensagem do envelope, sem fechar', async () => {
    const { employees } = mockApis();
    employees.deleteConflicts.set(2, {
      message: 'Não é possível excluir: existem registros ativos vinculados (equipamentos).',
      dependents: ['equipments'],
    });
    const user = userEvent.setup();
    await renderPage();
    await user.click(
      await screen.findByRole('button', { name: 'Excluir MAT-002 — Pedro Mecânico' }),
    );
    const dialog = await screen.findByRole('alertdialog', { name: 'Excluir colaborador?' });
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('(equipamentos)');
  });

  it('líder: vê a lista, sem escrita e sem carregar lookups de usuário/centro de custo', async () => {
    const { users, costCenters } = mockApis();
    await renderPage('leader');
    await screen.findByText('João Motorista');
    expect(screen.queryByRole('button', { name: 'Novo colaborador' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Editar|Excluir/ })).toBeNull();
    expect(users.listRequests).toHaveLength(0);
    expect(costCenters.listRequests).toHaveLength(0);
  });

  it.each<Role>(['operator', 'mechanic'])('%s sem employees.view → /403', async (role) => {
    const { employees } = mockApis();
    mockAuthApi(role);
    storeSession();
    const { location } = renderApp('/cadastros/colaboradores');
    await waitFor(() => expect(location()).toBe('/403'));
    expect(employees.listRequests).toHaveLength(0);
  });

  it('sem violações axe (lista com abas e formulário); obrigatórios com *', async () => {
    mockApis();
    const { container } = await renderPage();
    await screen.findByText('João Motorista');
    expect(await axe(container)).toHaveNoViolations();
    const { dialog } = await openCreate();
    const labels = [...dialog.querySelectorAll('label')].map((l) => l.textContent?.trim());
    expect(labels).toEqual(expect.arrayContaining(['Matrícula *', 'Nome *', 'Tipo *', 'Filial *']));
    expect(await axe(dialog)).toHaveNoViolations();
  });
});
