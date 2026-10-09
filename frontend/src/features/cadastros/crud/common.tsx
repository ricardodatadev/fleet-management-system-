import { Badge } from '@/components/ui';
import type { CrudColumn, CrudField, CrudFilter, CrudRow } from './types';

type WithActive = CrudRow & { is_active: boolean };

/**
 * Coluna de situação; o CrudPage troca por "Excluído" quando `deleted_at` está preenchido.
 * `sortable: false` quando `is_active` não está na whitelist de `sort` do recurso (→ 422).
 */
export function activeColumn<T extends WithActive>({ sortable = true } = {}): CrudColumn<T> {
  return {
    key: 'is_active',
    header: 'Situação',
    sortField: sortable ? 'is_active' : undefined,
    cell: (row) =>
      row.is_active ? <Badge tone="success">Ativo</Badge> : <Badge tone="neutral">Inativo</Badge>,
  };
}

export const activeFilter: CrudFilter = {
  name: 'is_active',
  label: 'Situação',
  allLabel: 'Todas',
  options: [
    { value: '1', label: 'Ativos' },
    { value: '0', label: 'Inativos' },
  ],
};

export function activeField<T extends WithActive>(): CrudField<T> {
  return { kind: 'switch', name: 'is_active', label: 'Ativo' };
}

export function codeField<T extends CrudRow>(maxLength: number): CrudField<T> {
  return {
    kind: 'text',
    name: 'code',
    label: 'Código',
    required: true,
    maxLength,
    help: 'Gravado em maiúsculas.',
  };
}

export function nameField<T extends CrudRow>(): CrudField<T> {
  return { kind: 'text', name: 'name', label: 'Nome', required: true, maxLength: 120 };
}
