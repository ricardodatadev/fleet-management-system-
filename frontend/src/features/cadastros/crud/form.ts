import type { FieldErrors } from '@/api';
import type { CrudField, CrudRow, FormValues } from './types';

export const REQUIRED_MESSAGE = 'Campo obrigatório.';

function rawValue<T extends CrudRow>(field: CrudField<T>, row: T): unknown {
  return field.get ? field.get(row) : (row as unknown as Record<string, unknown>)[field.name];
}

/** Valores do formulário: do registro (edição) ou os defaults (criação). */
export function initialValues<T extends CrudRow>(
  fields: CrudField<T>[],
  row?: T | null,
): FormValues {
  const values: FormValues = {};
  for (const field of fields) {
    if (!row) {
      values[field.name] = field.defaultValue ?? (field.kind === 'switch' ? true : '');
      continue;
    }
    const value = rawValue(field, row);
    values[field.name] =
      field.kind === 'switch'
        ? Boolean(value)
        : value === null || value === undefined
          ? ''
          : String(value);
  }
  return values;
}

/** Payload da API: vazio vira `null` em campo nullable e é omitido nos demais. */
export function toPayload<T extends CrudRow>(
  fields: CrudField<T>[],
  values: FormValues,
): Record<string, unknown> {
  const payload: Record<string, unknown> = {};
  for (const field of fields) {
    const value = values[field.name];
    if (field.kind === 'switch') {
      payload[field.name] = Boolean(value);
      continue;
    }
    const text = typeof value === 'string' ? value.trim() : '';
    if (text === '') {
      if (field.nullable) payload[field.name] = null;
      continue;
    }
    const numeric = field.kind === 'number' || (field.kind === 'select' && field.numeric);
    payload[field.name] = numeric ? Number(text) : text;
  }
  return payload;
}

/** Validação mínima no cliente (obrigatórios); o 422 do servidor segue como fonte da verdade. */
export function validate<T extends CrudRow>(
  fields: CrudField<T>[],
  values: FormValues,
): FieldErrors {
  const errors: FieldErrors = {};
  for (const field of fields) {
    const value = values[field.name];
    if (field.required && field.kind !== 'switch' && String(value ?? '').trim() === '') {
      errors[field.name] = [REQUIRED_MESSAGE];
    }
  }
  return errors;
}

/** Separa os erros do 422 entre campos do formulário e o restante (mostrado no topo). */
export function splitFieldErrors<T extends CrudRow>(
  fields: CrudField<T>[],
  errors: FieldErrors | null,
): { byField: Record<string, string>; other: string[] } {
  const names = new Set(fields.map((field) => field.name));
  const byField: Record<string, string> = {};
  const other: string[] = [];
  for (const [key, messages] of Object.entries(errors ?? {})) {
    const message = messages[0];
    if (!message) continue;
    if (names.has(key)) byField[key] = message;
    else other.push(message);
  }
  return { byField, other };
}
