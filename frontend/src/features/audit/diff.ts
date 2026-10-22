import { FIELD_LABELS, labelFor } from './labels';
import type { AuditLog } from './types';

type Values = Record<string, unknown> | null;

/** Uma linha do diff: campo → antes/depois. `hasBefore`/`hasAfter` = a chave existe no lado. */
export interface DiffRow {
  field: string;
  before: unknown;
  after: unknown;
  hasBefore: boolean;
  hasAfter: boolean;
}

const has = (values: Values, key: string) =>
  values !== null && Object.prototype.hasOwnProperty.call(values, key);

/**
 * Campos de old/new na ordem em que aparecem (old primeiro, depois os só de new). Em `created` só
 * há "depois"; em `deleted`, só "antes"; em `updated`, só os campos alterados (F.1).
 */
export function diffRows(log: Pick<AuditLog, 'old_values' | 'new_values'>): DiffRow[] {
  const fields = [
    ...Object.keys(log.old_values ?? {}),
    ...Object.keys(log.new_values ?? {}).filter((key) => !has(log.old_values, key)),
  ];
  return fields.map((field) => ({
    field,
    before: log.old_values?.[field],
    after: log.new_values?.[field],
    hasBefore: has(log.old_values, field),
    hasAfter: has(log.new_values, field),
  }));
}

/** Objeto/array: vai para bloco JSON formatado (o resto é texto em linha). */
export function isStructured(value: unknown): value is object {
  return typeof value === 'object' && value !== null;
}

/** Texto de um valor escalar; `null` e `''` têm rótulos próprios para não sumirem do diff. */
export function scalarText(value: unknown): string {
  if (value === null || value === undefined) return 'nulo';
  if (value === '') return 'vazio';
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não';
  if (typeof value === 'string') return value;
  return String(value);
}

export function jsonText(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

/** Resumo para a coluna da tabela: os campos alterados (até 3) ou nada. */
export function changedFieldsSummary(log: Pick<AuditLog, 'old_values' | 'new_values'>): string {
  const fields = diffRows(log).map((row) => labelFor(FIELD_LABELS, row.field));
  if (fields.length === 0) return '—';
  const shown = fields.slice(0, 3).join(', ');
  return fields.length > 3 ? `${shown} e mais ${fields.length - 3}` : shown;
}
