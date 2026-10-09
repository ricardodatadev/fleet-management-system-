import { CrudPage } from './crud/CrudPage';
import { activeColumn, activeField, activeFilter, codeField, nameField } from './crud/common';
import type { CrudResource } from './crud/types';
import { CATEGORY_LABELS, CRITICALITY_LABELS, enumOptions, labelOf } from './labels';
import { useMetaEnums } from './lookups';
import type { EquipmentFamily } from './types';

const percent = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 });

function tolerance(row: EquipmentFamily): string {
  const parts = [
    row.tolerance_km !== null && `${row.tolerance_km} km`,
    row.tolerance_hours !== null && `${row.tolerance_hours} h`,
    row.tolerance_days !== null && `${row.tolerance_days} dias`,
  ].filter(Boolean);
  return parts.length > 0 ? parts.join(' · ') : '—';
}

/**
 * Famílias/Classes (`/equipment-families`, spec C.4). Campos de RN-001 só persistem (sem motor).
 * Categoria e criticidade vêm de `/meta/enums` (`equipment_categories`, `criticalities`).
 */
export function FamiliesPage() {
  const enums = useMetaEnums();
  const categoryOptions = enumOptions(enums.data?.equipment_categories, CATEGORY_LABELS);
  const criticalityOptions = enumOptions(enums.data?.criticalities, CRITICALITY_LABELS);

  const resource: CrudResource<EquipmentFamily> = {
    endpoint: '/equipment-families',
    title: 'Famílias/Classes',
    description: 'Classes de equipamento com criticidade e janelas da preventiva.',
    labels: {
      create: 'Nova família',
      edit: 'Editar família',
      singular: 'família',
      created: 'Família cadastrada',
    },
    permissions: { view: 'equipment_families.view', manage: 'equipment_families.manage' },
    searchPlaceholder: 'Código ou nome',
    describe: (row) => `${row.code} — ${row.name}`,
    columns: [
      { key: 'code', header: 'Código', sortField: 'code', cell: (row) => row.code },
      { key: 'name', header: 'Nome', sortField: 'name', cell: (row) => row.name },
      {
        key: 'category',
        header: 'Categoria',
        sortField: 'category',
        cell: (row) => labelOf(CATEGORY_LABELS, row.category),
      },
      {
        key: 'criticality',
        header: 'Criticidade',
        sortField: 'criticality',
        cell: (row) => labelOf(CRITICALITY_LABELS, row.criticality),
      },
      {
        key: 'preventive_lead_pct',
        header: 'Pré-alerta',
        cell: (row) => `${percent.format(row.preventive_lead_pct)}%`,
      },
      { key: 'tolerance', header: 'Tolerância', cell: tolerance },
      activeColumn(),
    ],
    filters: [
      { name: 'category', label: 'Categoria', options: categoryOptions, loading: enums.isPending },
      {
        name: 'criticality',
        label: 'Criticidade',
        allLabel: 'Todas',
        options: criticalityOptions,
        loading: enums.isPending,
      },
      activeFilter,
    ],
    fields: [
      codeField(30),
      nameField(),
      {
        kind: 'select',
        name: 'category',
        label: 'Categoria',
        required: true,
        options: categoryOptions,
        loading: enums.isPending,
      },
      {
        kind: 'select',
        name: 'criticality',
        label: 'Criticidade',
        required: true,
        options: criticalityOptions,
        loading: enums.isPending,
        defaultValue: 'medium',
      },
      {
        kind: 'number',
        name: 'preventive_lead_pct',
        label: 'Pré-alerta (% do intervalo)',
        help: 'Margem de antecedência da preventiva: maior que 0 e até 100.',
        required: true,
        min: 0.01,
        max: 100,
        step: 0.01,
        defaultValue: '90',
      },
      {
        kind: 'number',
        name: 'tolerance_km',
        label: 'Tolerância (km)',
        min: 0,
        step: 1,
        nullable: true,
      },
      {
        kind: 'number',
        name: 'tolerance_hours',
        label: 'Tolerância (horas)',
        min: 0,
        step: 1,
        nullable: true,
      },
      {
        kind: 'number',
        name: 'tolerance_days',
        label: 'Tolerância (dias)',
        min: 0,
        step: 1,
        nullable: true,
      },
      activeField(),
    ],
  };

  return <CrudPage resource={resource} />;
}
