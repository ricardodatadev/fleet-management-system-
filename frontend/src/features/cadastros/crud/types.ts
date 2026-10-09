import type { ReactNode } from 'react';
import type { SelectOption } from '@/components/ui';

/** Todo registro de cadastro tem id e `deleted_at` (sempre presente; D.2). */
export interface CrudRow {
  id: number;
  deleted_at: string | null;
}

export interface CrudColumn<T> {
  key: string;
  header: string;
  /** Campo de `sort` na API (whitelist do recurso). Omitir = coluna não ordenável. */
  sortField?: string;
  cell: (row: T) => ReactNode;
  className?: string;
}

/** Filtro plano da lista (D.2). Valor vazio = sem filtro. */
export interface CrudFilter {
  name: string;
  label: string;
  options: SelectOption[];
  /** Rótulo da opção "sem filtro". */
  allLabel?: string;
  loading?: boolean;
}

interface FieldBase<T> {
  name: string;
  label: string;
  required?: boolean;
  help?: string;
  /** Valor inicial a partir do registro (padrão: `row[name]`). */
  get?: (row: T) => unknown;
  /** Valor no formulário de criação (padrão: vazio / `true` para switch). */
  defaultValue?: string | boolean;
}

export interface TextField<T> extends FieldBase<T> {
  kind: 'text';
  maxLength?: number;
  /** Vazio vira `null` no payload (coluna nullable); senão o campo é omitido. */
  nullable?: boolean;
}

export interface NumberField<T> extends FieldBase<T> {
  kind: 'number';
  min?: number;
  max?: number;
  step?: number;
  nullable?: boolean;
}

export interface SelectField<T> extends FieldBase<T> {
  kind: 'select';
  options: SelectOption[];
  loading?: boolean;
  /** Valor enviado como número (ex.: FK `branch_id`). */
  numeric?: boolean;
  nullable?: boolean;
  /** Rótulo da opção vazia (ex.: "Sem filial"). */
  placeholder?: string;
  /** Opção do valor atual quando ele não está entre as opções (ex.: filial inativa). */
  currentOption?: (row: T) => SelectOption | null;
}

export interface SwitchField<T> extends FieldBase<T> {
  kind: 'switch';
}

export type CrudField<T> = TextField<T> | NumberField<T> | SelectField<T> | SwitchField<T>;

export type FormValues = Record<string, string | boolean>;

/** Configuração de um recurso para o CrudPage (spec F1-30). */
export interface CrudResource<T extends CrudRow> {
  /** Rota da API, ex.: `/branches`. Também é o prefixo das chaves de cache. */
  endpoint: string;
  title: string;
  description?: string;
  /** Rótulos de UI, ex.: "Nova unidade", "Editar unidade", "unidade", "Unidade cadastrada". */
  labels: { create: string; edit: string; singular: string; created: string };
  permissions: { view: string; manage: string };
  columns: CrudColumn<T>[];
  filters?: CrudFilter[];
  fields: CrudField<T>[];
  /** Identificação curta do registro (confirmação, toasts, rótulos das ações). */
  describe: (row: T) => string;
  /** Placeholder da busca `q` (campos pesquisados). */
  searchPlaceholder?: string;
}
