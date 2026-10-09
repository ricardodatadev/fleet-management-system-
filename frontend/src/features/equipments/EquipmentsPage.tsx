import { Badge, StatusPill, useToast } from '@/components/ui';
import { useAuth, useCan } from '@/features/auth';
import { CrudPage } from '@/features/cadastros/crud/CrudPage';
import type { CrudFilter, CrudResource } from '@/features/cadastros/crud/types';
import { enumOptions } from '@/features/cadastros/labels';
import { useBranchOptions, useFamilyOptions, useMetaEnums } from '@/features/cadastros/lookups';
import { FORM_SOON, STATUS_LABELS } from './labels';
import type { Equipment } from './types';

const ref = (item: { code: string; name: string } | null) => (item ? item.name : '—');

/**
 * Frotas & Equipamentos (G.4-2, F1-25): lista server-side com busca, filial, família e status na
 * URL. Escrita só com `equipments.manage`; criar/editar abrem o formulário da F1-26 (por ora, um
 * aviso). Quem tem filial fixa (não-admin) já recebe só a própria filial da API: o filtro de filial
 * some para ele.
 */
export function EquipmentsPage() {
  const auth = useAuth();
  const can = useCan();
  const { toast } = useToast();
  const enums = useMetaEnums();
  const fixedBranch = auth.status === 'authenticated' ? auth.user.branch : null;
  const canSeeFamilies = can('equipment_families.view');
  const branches = useBranchOptions(fixedBranch === null);
  const families = useFamilyOptions(canSeeFamilies);

  const soon = () => toast({ tone: 'info', title: 'Em breve', description: FORM_SOON });

  const filters: CrudFilter[] = [];
  if (fixedBranch === null) {
    filters.push({
      name: 'branch_id',
      label: 'Filial',
      allLabel: 'Todas',
      options: branches.data ?? [],
      loading: branches.isPending,
    });
  }
  if (canSeeFamilies) {
    filters.push({
      name: 'family_id',
      label: 'Família/Classe',
      allLabel: 'Todas',
      options: families.data ?? [],
      loading: families.isPending,
    });
  }
  filters.push({
    name: 'status',
    label: 'Status',
    options: enumOptions(enums.data?.equipment_statuses, STATUS_LABELS),
    loading: enums.isPending,
  });

  const resource: CrudResource<Equipment> = {
    endpoint: '/equipments',
    title: 'Frotas & Equipamentos',
    description: fixedBranch ? `Equipamentos da filial ${fixedBranch.name}.` : undefined,
    labels: {
      create: 'Cadastrar Nova Frota',
      edit: 'Editar frota',
      singular: 'equipamento',
      created: 'Equipamento cadastrado',
    },
    permissions: { view: 'equipments.view', manage: 'equipments.manage' },
    searchPlaceholder: 'Código, nome ou placa',
    describe: (row) => `${row.code} — ${row.name}`,
    onCreate: soon,
    onEdit: soon,
    columns: [
      { key: 'code', header: 'Código', sortField: 'code', cell: (row) => row.code },
      { key: 'name', header: 'Nome', sortField: 'name', cell: (row) => row.name },
      { key: 'family', header: 'Classe', cell: (row) => ref(row.family) },
      { key: 'plate', header: 'Placa', sortField: 'plate', cell: (row) => row.plate ?? '—' },
      {
        key: 'responsible',
        header: 'Responsável',
        cell: (row) => row.responsible_employee?.name ?? '—',
      },
      { key: 'branch', header: 'Filial', cell: (row) => ref(row.branch) },
      {
        key: 'status',
        header: 'Status',
        sortField: 'status',
        cell: (row) =>
          row.deleted_at ? (
            <Badge tone="danger">Excluído</Badge>
          ) : (
            <StatusPill status={row.status} />
          ),
      },
    ],
    filters,
    fields: [],
  };

  return <CrudPage resource={resource} />;
}
