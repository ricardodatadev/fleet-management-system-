import { Badge } from '@/components/ui';
import { ROLE_LABELS, useAuth } from '@/features/auth';
import { PASSWORD_POLICY_HELP } from '@/features/auth/ResetPasswordPage';
import { CrudPage } from './crud/CrudPage';
import { activeColumn, activeField, activeFilter, nameField } from './crud/common';
import type { CrudResource } from './crud/types';
import { enumOptions, labelOf } from './labels';
import { useBranchOptions, useMetaEnums } from './lookups';
import type { User } from './types';

/** Regra de username da v1.9 (mesmo texto do 422 do backend). */
export const USERNAME_HELP =
  'Use letras minúsculas sem acento, números e ponto (não no início, no fim nem repetido), de 3 a 30 caracteres.';

export const SELF_ROLE_LOCK = 'Você não pode alterar o próprio perfil.';
export const SELF_ACTIVE_LOCK = 'Você não pode desativar a própria conta.';

const lastLogin = new Intl.DateTimeFormat('pt-BR', {
  dateStyle: 'short',
  timeStyle: 'short',
  timeZone: 'America/Sao_Paulo',
});

/**
 * Usuários (`/users`, só admin; spec D.2 v1.3/v1.9). Regras de proteção do backend (409): ninguém
 * exclui, desativa ou muda o perfil da própria conta, e o último admin ativo não pode sair. Na
 * própria linha, excluir some e perfil/ativo ficam travados com o motivo.
 */
export function UsersPage() {
  const auth = useAuth();
  const meId = auth.status === 'authenticated' ? auth.user.id : null;
  const enums = useMetaEnums();
  const branches = useBranchOptions();
  const isSelf = (row: User | null) => row !== null && row.id === meId;

  const resource: CrudResource<User> = {
    endpoint: '/users',
    title: 'Usuários',
    description:
      'Acesso ao sistema. Você não pode excluir, desativar nem mudar o perfil da própria conta, e o sistema sempre mantém ao menos um administrador ativo.',
    labels: {
      create: 'Novo usuário',
      edit: 'Editar usuário',
      singular: 'usuário',
      created: 'Usuário cadastrado',
    },
    permissions: { view: 'users.view', manage: 'users.manage' },
    searchPlaceholder: 'Nome, usuário ou e-mail',
    describe: (row) => `${row.name} (${row.username})`,
    canDelete: (row) => !isSelf(row),
    columns: [
      {
        key: 'name',
        header: 'Nome',
        sortField: 'name',
        cell: (row) => (
          <span className="inline-flex items-center gap-2">
            {row.name}
            {isSelf(row) && <Badge tone="info">Você</Badge>}
          </span>
        ),
      },
      { key: 'username', header: 'Usuário', sortField: 'username', cell: (row) => row.username },
      { key: 'email', header: 'E-mail', sortField: 'email', cell: (row) => row.email },
      {
        key: 'role',
        header: 'Perfil',
        sortField: 'role',
        cell: (row) => labelOf(ROLE_LABELS, row.role),
      },
      {
        key: 'branch',
        header: 'Filial',
        cell: (row) => (row.branch ? `${row.branch.code} — ${row.branch.name}` : 'Todas'),
      },
      {
        key: 'employee',
        header: 'Colaborador',
        cell: (row) => (row.employee ? `${row.employee.registration} — ${row.employee.name}` : '—'),
      },
      {
        key: 'last_login_at',
        header: 'Último acesso',
        sortField: 'last_login_at',
        cell: (row) => (row.last_login_at ? lastLogin.format(new Date(row.last_login_at)) : '—'),
      },
      // Ordenação de usuários não inclui is_active.
      activeColumn({ sortable: false }),
    ],
    filters: [
      {
        name: 'role',
        label: 'Perfil',
        options: enumOptions(enums.data?.roles, ROLE_LABELS),
        loading: enums.isPending,
      },
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
      nameField(),
      {
        kind: 'text',
        name: 'username',
        label: 'Usuário',
        required: true,
        maxLength: 30,
        autoComplete: 'off',
        help: USERNAME_HELP,
      },
      {
        kind: 'text',
        name: 'email',
        label: 'E-mail',
        required: true,
        maxLength: 190,
        autoComplete: 'off',
      },
      {
        kind: 'password',
        name: 'password',
        label: 'Senha',
        // Obrigatória só no cadastro; na edição, em branco mantém a atual.
        required: ({ row }) => row === null,
        help: `${PASSWORD_POLICY_HELP} Na edição, deixe em branco para manter a senha atual.`,
        get: () => '',
      },
      {
        kind: 'select',
        name: 'role',
        label: 'Perfil',
        required: true,
        options: enumOptions(enums.data?.roles, ROLE_LABELS),
        loading: enums.isPending,
        locked: (row) => (isSelf(row) ? SELF_ROLE_LOCK : null),
      },
      {
        kind: 'select',
        name: 'branch_id',
        label: 'Filial',
        help: 'Obrigatória para quem não é administrador.',
        numeric: true,
        nullable: true,
        placeholder: 'Todas as filiais (só administrador)',
        // Para admin é opcional; para os demais perfis é obrigatória (422 no backend também).
        required: ({ values }) => values.role !== '' && values.role !== 'admin',
        options: branches.data ?? [],
        loading: branches.isPending,
        get: (row) => row.branch?.id ?? null,
        currentOption: (row) =>
          row.branch
            ? { value: String(row.branch.id), label: `${row.branch.code} — ${row.branch.name}` }
            : null,
      },
      { ...activeField<User>(), locked: (row) => (isSelf(row) ? SELF_ACTIVE_LOCK : null) },
    ],
  };

  return <CrudPage resource={resource} />;
}
