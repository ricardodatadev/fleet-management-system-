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

/** Contexto de um campo no formulário: registro em edição (`null` = criação) e valores atuais. */
export interface FieldContext<T> {
  row: T | null;
  values: FormValues;
}

interface FieldBase<T> {
  name: string;
  label: string;
  /** Obrigatório (asterisco + pré-checagem); função quando depende do contexto (ex.: só no create). */
  required?: boolean | ((ctx: FieldContext<T>) => boolean);
  help?: string;
  /**
   * Mostra o campo conforme os valores (ex.: CNH só para motorista). Oculto: não é validado e, se
   * for `nullable`, vai como `null` no payload (limpa o que sobrou de um tipo anterior).
   */
  visible?: (values: FormValues) => boolean;
  /** Campo travado neste registro: devolve o motivo (exibido como ajuda) e o campo sai do payload. */
  locked?: (row: T | null) => string | null;
  /** Campos que, ao mudar, limpam este (ex.: centro de custo depende da filial). */
  dependsOn?: string[];
  /** Valor inicial a partir do registro (padrão: `row[name]`). */
  get?: (row: T) => unknown;
  /** Valor no formulário de criação (padrão: vazio / `true` para switch). */
  defaultValue?: string | boolean;
}

export interface TextField<T> extends FieldBase<T> {
  /** `date` = input de data (yyyy-mm-dd); `password` = senha com mostrar/ocultar. */
  kind: 'text' | 'date' | 'password';
  maxLength?: number;
  autoComplete?: string;
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
  /** Opções fixas, ou calculadas pelos valores do form (ex.: centros de custo da filial escolhida). */
  options: SelectOption[] | ((values: FormValues) => SelectOption[]);
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
  /**
   * Abas acima da lista, ligadas a um parâmetro da API/URL (ex.: `job_type`). O valor `''` é a aba
   * "todos"; um valor pode listar vários, separados por vírgula, se a API aceitar.
   */
  tabs?: { param: string; label: string; items: { value: string; label: string }[] };
  /** Esconde a ação de excluir numa linha (ex.: o próprio usuário). Padrão: todas podem. */
  canDelete?: (row: T) => boolean;
  /**
   * Substituem o formulário embutido (Modal gerado de `fields`) quando a tela tem o próprio fluxo
   * de criar/editar (ex.: equipamentos, com wizard por abas).
   */
  onCreate?: () => void;
  onEdit?: (row: T) => void;
}
