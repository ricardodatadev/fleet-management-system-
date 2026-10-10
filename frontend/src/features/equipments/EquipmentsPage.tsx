import { Suspense, lazy, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Badge, StatusPill } from '@/components/ui';
import { useAuth, useCan } from '@/features/auth';
import { CrudPage } from '@/features/cadastros/crud/CrudPage';
import type { CrudFilter, CrudResource } from '@/features/cadastros/crud/types';
import { enumOptions } from '@/features/cadastros/labels';
import { useBranchOptions, useFamilyOptions, useMetaEnums } from '@/features/cadastros/lookups';
import { EquipmentDrawer } from './EquipmentDrawer';
import { STATUS_LABELS } from './labels';
import type { Equipment } from './types';

// O formulário (react-hook-form + zod) fica num chunk à parte: só baixa ao abrir Cadastrar/Editar.
const EquipmentFormModal = lazy(() =>
  import('./EquipmentFormModal').then((module) => ({ default: module.EquipmentFormModal })),
);

const ref = (item: { code: string; name: string } | null) => (item ? item.name : '—');

/**
 * Frotas & Equipamentos (G.4-2, F1-25): lista server-side com busca, filial, família e status na
 * URL. Escrita só com `equipments.manage`; criar/editar abrem o formulário por abas (F1-26). Quem tem filial fixa (não-admin) já recebe só a própria filial da API: o filtro de filial
 * some para ele.
 */
export function EquipmentsPage() {
  const auth = useAuth();
  const can = useCan();
  const enums = useMetaEnums();
  const fixedBranch = auth.status === 'authenticated' ? auth.user.branch : null;
  const canSeeFamilies = can('equipment_families.view');
  const branches = useBranchOptions(fixedBranch === null);
  const families = useFamilyOptions(canSeeFamilies);

  // `undefined` = fechado; `null` = cadastro; registro = edição.
  const [editing, setEditing] = useState<Equipment | null | undefined>(undefined);
  // Drawer 360° pelo `?id=` da URL (deep-link); fechar remove o id e mantém os filtros.
  const [params, setParams] = useSearchParams();
  const drawerId = Number(params.get('id')) || null;
  function setDrawer(id: number | null) {
    setParams(
      (current) => {
        const next = new URLSearchParams(current);
        if (id === null) next.delete('id');
        else next.set('id', String(id));
        return next;
      },
      { replace: true },
    );
  }

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
    onCreate: () => setEditing(null),
    onEdit: (row) => setEditing(row),
    onRowClick: (row) => setDrawer(row.id),
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

  return (
    <>
      <CrudPage resource={resource} />
      {drawerId !== null && (
        <EquipmentDrawer
          key={drawerId}
          id={drawerId}
          onClose={() => setDrawer(null)}
          onEdit={
            can('equipments.manage')
              ? (equipment) => {
                  setDrawer(null);
                  setEditing(equipment);
                }
              : undefined
          }
        />
      )}
      {editing !== undefined && (
        <Suspense fallback={null}>
          <EquipmentFormModal row={editing} onClose={() => setEditing(undefined)} />
        </Suspense>
      )}
    </>
  );
}
