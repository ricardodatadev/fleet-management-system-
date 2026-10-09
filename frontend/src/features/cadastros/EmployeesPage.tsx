import { useCan } from '@/features/auth';
import { CrudPage } from './crud/CrudPage';
import { activeColumn, activeField, activeFilter, nameField } from './crud/common';
import type { CrudResource, FormValues } from './crud/types';
import { JOB_TYPE_LABELS, enumOptions, labelOf } from './labels';
import { useBranchOptions, useCostCenterLookup, useMetaEnums, useUserLookup } from './lookups';
import type { Employee } from './types';

const NO_COST_CENTER = 'Sem centro de custo';
const NO_USER = 'Sem acesso ao sistema';

const ref = (item: { id: number; code: string; name: string }) => ({
  value: String(item.id),
  label: `${item.code} — ${item.name}`,
});

const isDriver = (values: FormValues) => values.job_type === 'driver';
const isMechanic = (values: FormValues) => values.job_type === 'mechanic';

/**
 * Colaboradores (`/employees`, spec C.5/D.2 v1.5). Abas por `job_type` (Equipe Adm = líder +
 * equipe administrativa), campos por tipo (CNH só motorista; especialidade e custo/hora só
 * mecânico), centro de custo da filial escolhida (ou sem filial) e vínculo opcional com usuário.
 */
export function EmployeesPage() {
  const can = useCan();
  const canManage = can('employees.manage');
  const enums = useMetaEnums();
  const branches = useBranchOptions();
  const costCenters = useCostCenterLookup(canManage);
  const users = useUserLookup(canManage && can('users.view'));

  const jobTypeOptions = enumOptions(enums.data?.job_types, JOB_TYPE_LABELS);
  const cnhOptions = (enums.data?.cnh_categories ?? []).map((value) => ({ value, label: value }));

  const costCenterOptions = (values: FormValues) =>
    (costCenters.data ?? [])
      .filter((cc) => !cc.branch || String(cc.branch.id) === values.branch_id)
      .map(ref);

  // Só usuários livres (sem colaborador vinculado); o atual entra pelo currentOption na edição.
  const userOptions = (users.data ?? [])
    .filter((user) => !user.employee)
    .map((user) => ({ value: String(user.id), label: `${user.name} (${user.username})` }));

  const resource: CrudResource<Employee> = {
    endpoint: '/employees',
    title: 'Pessoas & Colaboradores',
    description:
      'Motoristas, mecânicos e equipe administrativa, com o vínculo ao usuário do sistema.',
    labels: {
      create: 'Novo colaborador',
      edit: 'Editar colaborador',
      singular: 'colaborador',
      created: 'Colaborador cadastrado',
    },
    permissions: { view: 'employees.view', manage: 'employees.manage' },
    searchPlaceholder: 'Nome ou matrícula',
    describe: (row) => `${row.registration} — ${row.name}`,
    tabs: {
      param: 'job_type',
      label: 'Tipo de colaborador',
      items: [
        { value: '', label: 'Todos' },
        { value: 'driver', label: 'Motoristas' },
        { value: 'mechanic', label: 'Mecânicos' },
        { value: 'leader,admin_staff', label: 'Equipe Adm' },
      ],
    },
    columns: [
      {
        key: 'registration',
        header: 'Matrícula',
        sortField: 'registration',
        cell: (row) => row.registration,
      },
      { key: 'name', header: 'Nome', sortField: 'name', cell: (row) => row.name },
      {
        key: 'job_type',
        header: 'Tipo',
        sortField: 'job_type',
        cell: (row) => labelOf(JOB_TYPE_LABELS, row.job_type),
      },
      { key: 'branch', header: 'Filial', cell: (row) => `${row.branch.code} — ${row.branch.name}` },
      {
        key: 'cost_center',
        header: 'Centro de custo',
        cell: (row) => (row.cost_center ? row.cost_center.code : '—'),
      },
      {
        key: 'user',
        header: 'Usuário vinculado',
        cell: (row) => (row.user ? row.user.name : '—'),
      },
      // Ordenação de colaboradores: name, registration, job_type, hired_at (sem is_active).
      activeColumn({ sortable: false }),
    ],
    filters: [
      {
        name: 'branch_id',
        label: 'Filial',
        allLabel: 'Todas',
        options: branches.data ?? [],
        loading: branches.isPending,
      },
      activeFilter,
    ],
    fields: [
      {
        kind: 'text',
        name: 'registration',
        label: 'Matrícula',
        required: true,
        maxLength: 30,
        help: 'Gravada em maiúsculas.',
      },
      nameField(),
      {
        kind: 'select',
        name: 'job_type',
        label: 'Tipo',
        required: true,
        options: jobTypeOptions,
        loading: enums.isPending,
      },
      {
        kind: 'select',
        name: 'branch_id',
        label: 'Filial',
        required: true,
        numeric: true,
        options: branches.data ?? [],
        loading: branches.isPending,
        get: (row) => row.branch.id,
        currentOption: (row) => ref(row.branch),
      },
      {
        kind: 'select',
        name: 'cost_center_id',
        label: 'Centro de custo',
        help: 'Da filial escolhida ou sem filial.',
        numeric: true,
        nullable: true,
        placeholder: NO_COST_CENTER,
        options: costCenterOptions,
        loading: costCenters.isPending,
        dependsOn: ['branch_id'],
        get: (row) => row.cost_center?.id ?? null,
        currentOption: (row) => (row.cost_center ? ref(row.cost_center) : null),
      },
      {
        kind: 'select',
        name: 'user_id',
        label: 'Usuário vinculado',
        help: 'Opcional: o acesso ao sistema deste colaborador.',
        numeric: true,
        nullable: true,
        placeholder: NO_USER,
        options: userOptions,
        loading: users.isPending,
        get: (row) => row.user?.id ?? null,
        currentOption: (row) =>
          row.user
            ? { value: String(row.user.id), label: `${row.user.name} (${row.user.email})` }
            : null,
      },
      { kind: 'text', name: 'phone', label: 'Telefone', maxLength: 30, nullable: true },
      { kind: 'date', name: 'hired_at', label: 'Admissão', nullable: true },
      // Motorista
      {
        kind: 'text',
        name: 'cnh_number',
        label: 'Número da CNH',
        maxLength: 20,
        nullable: true,
        visible: isDriver,
      },
      {
        kind: 'select',
        name: 'cnh_category',
        label: 'Categoria da CNH',
        nullable: true,
        placeholder: 'Sem categoria',
        options: cnhOptions,
        visible: isDriver,
      },
      {
        kind: 'date',
        name: 'cnh_expires_at',
        label: 'Validade da CNH',
        nullable: true,
        visible: isDriver,
      },
      // Mecânico
      {
        kind: 'text',
        name: 'specialty',
        label: 'Especialidade',
        maxLength: 80,
        nullable: true,
        visible: isMechanic,
      },
      {
        kind: 'number',
        name: 'hourly_cost',
        label: 'Custo por hora (R$)',
        min: 0,
        step: 0.01,
        nullable: true,
        visible: isMechanic,
      },
      activeField(),
    ],
  };

  return <CrudPage resource={resource} />;
}
