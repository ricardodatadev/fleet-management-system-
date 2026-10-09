import { CrudPage } from './crud/CrudPage';
import { activeColumn, activeField, activeFilter, codeField, nameField } from './crud/common';
import type { CrudResource } from './crud/types';
import { useBranchOptions } from './lookups';
import type { CostCenter } from './types';

const NO_BRANCH = 'Sem filial';

/** Centros de Custo (`/cost-centers`, spec C.3): `branch` null = sem filial (visível a todas). */
export function CostCentersPage() {
  const branches = useBranchOptions();
  const branchOptions = branches.data ?? [];

  const resource: CrudResource<CostCenter> = {
    endpoint: '/cost-centers',
    title: 'Centros de Custo',
    description: 'Centros de custo por filial; sem filial = disponível para todas.',
    labels: {
      create: 'Novo centro de custo',
      edit: 'Editar centro de custo',
      singular: 'centro de custo',
      created: 'Centro de custo cadastrado',
    },
    permissions: { view: 'cost_centers.view', manage: 'cost_centers.manage' },
    searchPlaceholder: 'Código ou nome',
    describe: (row) => `${row.code} — ${row.name}`,
    columns: [
      { key: 'code', header: 'Código', sortField: 'code', cell: (row) => row.code },
      { key: 'name', header: 'Nome', sortField: 'name', cell: (row) => row.name },
      {
        key: 'branch',
        header: 'Filial',
        cell: (row) => (row.branch ? `${row.branch.code} — ${row.branch.name}` : NO_BRANCH),
      },
      activeColumn(),
    ],
    filters: [
      {
        name: 'branch_id',
        label: 'Filial',
        allLabel: 'Todas',
        options: branchOptions,
        loading: branches.isPending,
      },
      activeFilter,
    ],
    fields: [
      codeField(30),
      nameField(),
      {
        kind: 'select',
        name: 'branch_id',
        label: 'Filial',
        help: 'Deixe "Sem filial" para disponibilizar a todas as filiais.',
        options: branchOptions,
        loading: branches.isPending,
        numeric: true,
        nullable: true,
        placeholder: NO_BRANCH,
        get: (row) => row.branch?.id ?? null,
        // Filial inativa não vem no lookup (is_active=1), mas continua válida no registro.
        currentOption: (row) =>
          row.branch
            ? { value: String(row.branch.id), label: `${row.branch.code} — ${row.branch.name}` }
            : null,
      },
      activeField(),
    ],
  };

  return <CrudPage resource={resource} />;
}
