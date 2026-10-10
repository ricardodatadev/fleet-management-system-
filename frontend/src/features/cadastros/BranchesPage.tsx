import { CrudPage } from './crud/CrudPage';
import { activeColumn, activeField, activeFilter, codeField, nameField } from './crud/common';
import type { CrudResource } from './crud/types';
import { BRANCH_TYPE_LABELS, UFS, enumOptions, labelOf } from './labels';
import { useMetaEnums } from './lookups';
import type { Branch } from './types';

const UF_OPTIONS = UFS.map((uf) => ({ value: uf, label: uf }));

/** Unidades/Filiais (`/branches`, spec C.2): tipo vem de `/meta/enums` (`branch_types`). */
export function BranchesPage() {
  const enums = useMetaEnums();
  const typeOptions = enumOptions(enums.data?.branch_types, BRANCH_TYPE_LABELS);

  const resource: CrudResource<Branch> = {
    endpoint: '/branches',
    title: 'Unidades/Filiais',
    description: 'Filiais, garagens e oficinas da operação.',
    labels: {
      create: 'Nova unidade',
      edit: 'Editar unidade',
      singular: 'unidade',
      created: 'Unidade cadastrada',
    },
    permissions: { view: 'branches.view', manage: 'branches.manage' },
    searchPlaceholder: 'Código ou nome',
    describe: (row) => `${row.code} — ${row.name}`,
    columns: [
      { key: 'code', header: 'Código', sortField: 'code', cell: (row) => row.code },
      { key: 'name', header: 'Nome', sortField: 'name', cell: (row) => row.name },
      {
        key: 'type',
        header: 'Tipo',
        sortField: 'type',
        cell: (row) => labelOf(BRANCH_TYPE_LABELS, row.type),
      },
      {
        key: 'city',
        header: 'Cidade/UF',
        cell: (row) => [row.city, row.state].filter(Boolean).join('/') || '—',
      },
      activeColumn(),
    ],
    filters: [
      { name: 'type', label: 'Tipo', options: typeOptions, loading: enums.isPending },
      activeFilter,
    ],
    fields: [
      codeField(20),
      nameField(),
      {
        kind: 'select',
        name: 'type',
        label: 'Tipo',
        required: true,
        options: typeOptions,
        loading: enums.isPending,
      },
      { kind: 'text', name: 'city', label: 'Cidade', maxLength: 80, nullable: true },
      {
        kind: 'select',
        name: 'state',
        label: 'UF',
        options: UF_OPTIONS,
        nullable: true,
        placeholder: 'Sem UF',
      },
      activeField(),
    ],
  };

  return <CrudPage resource={resource} />;
}
