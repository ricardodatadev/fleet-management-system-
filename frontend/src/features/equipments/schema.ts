import { z } from 'zod';
import type { Equipment } from './types';

/**
 * Validação do formulário de equipamentos (F1-26), espelhando a API (EquipmentInput, D.2/C.6).
 * Os campos do form são texto (inputs/selects); o schema valida e devolve o payload da API.
 * O 422 do servidor continua a fonte da verdade (unicidade da placa, consistência de filial).
 */
export const REQUIRED = 'Campo obrigatório.';
export const PLATE_MESSAGE =
  'Placa inválida: use de 5 a 8 letras e números (hífen e espaço são ignorados).';
export const NOT_NEGATIVE = 'Informe um valor maior ou igual a zero.';

/** Placa como a API grava: maiúsculas, sem espaços nem hífen. */
export function normalizePlate(value: string): string {
  return value.toUpperCase().replace(/[\s-]+/g, '');
}

export const FORM_FIELDS = [
  'code',
  'name',
  'family_id',
  'branch_id',
  'plate',
  'serial_number',
  'manufacturer',
  'model',
  'year',
  'responsible_employee_id',
  'status',
  'criticality_override',
  'notes',
  'cost_center_id',
  'acquisition_date',
  'acquisition_value',
  'odometer_km',
  'hour_meter',
] as const;
export type FormField = (typeof FORM_FIELDS)[number];
export type EquipmentFormValues = Record<FormField, string>;

/** Aba de cada campo (para levar o usuário ao primeiro erro). */
export const FIELD_TAB: Record<FormField, 'gerais' | 'financeiro'> = {
  code: 'gerais',
  name: 'gerais',
  family_id: 'gerais',
  branch_id: 'gerais',
  plate: 'gerais',
  serial_number: 'gerais',
  manufacturer: 'gerais',
  model: 'gerais',
  year: 'gerais',
  responsible_employee_id: 'gerais',
  status: 'gerais',
  criticality_override: 'gerais',
  notes: 'gerais',
  cost_center_id: 'financeiro',
  acquisition_date: 'financeiro',
  acquisition_value: 'financeiro',
  odometer_km: 'financeiro',
  hour_meter: 'financeiro',
};

const text = (max: number) =>
  z
    .string()
    .trim()
    .max(max, `Use no máximo ${max} caracteres.`)
    .transform((value) => (value === '' ? null : value));

const required = (max: number) =>
  z.string().trim().min(1, REQUIRED).max(max, `Use no máximo ${max} caracteres.`);

const id = z
  .string()
  .min(1, REQUIRED)
  .transform((value) => Number(value));

const optionalId = z.string().transform((value) => (value === '' ? null : Number(value)));

/** Número ≥ 0 com no máximo `decimals` casas; vazio → `empty`. */
function amount(decimals: number, empty: number | null) {
  return z
    .string()
    .trim()
    .transform((value, ctx) => {
      if (value === '') return empty;
      const number = Number(value.replace(',', '.'));
      if (!Number.isFinite(number)) {
        ctx.addIssue({ code: 'custom', message: 'Informe um número.' });
        return z.NEVER;
      }
      if (number < 0) {
        ctx.addIssue({ code: 'custom', message: NOT_NEGATIVE });
        return z.NEVER;
      }
      if (Math.round(number * 10 ** decimals) !== number * 10 ** decimals) {
        ctx.addIssue({ code: 'custom', message: `Use no máximo ${decimals} casa(s) decimal(is).` });
        return z.NEVER;
      }
      return number;
    });
}

/** Schema do formulário; `now` define o limite do ano (ano atual + 1). */
export function equipmentSchema(now: Date = new Date()) {
  const maxYear = now.getFullYear() + 1;
  return z.object({
    code: required(30),
    name: required(150),
    family_id: id,
    branch_id: id,
    cost_center_id: id,
    responsible_employee_id: optionalId,
    plate: z
      .string()
      .transform((value) => normalizePlate(value))
      .refine((value) => value === '' || /^[A-Z0-9]{5,8}$/.test(value), PLATE_MESSAGE)
      .transform((value) => (value === '' ? null : value)),
    serial_number: text(60),
    manufacturer: text(80),
    model: text(80),
    year: z
      .string()
      .trim()
      .transform((value, ctx) => {
        if (value === '') return null;
        const year = Number(value);
        if (!Number.isInteger(year) || year < 1950 || year > maxYear) {
          ctx.addIssue({ code: 'custom', message: `Informe um ano entre 1950 e ${maxYear}.` });
          return z.NEVER;
        }
        return year;
      }),
    status: z.string().pipe(z.enum(['active', 'inactive', 'disposed'], { message: REQUIRED })),
    criticality_override: z
      .string()
      .transform((value) => (value === '' ? null : value))
      .pipe(z.enum(['low', 'medium', 'high', 'critical']).nullable()),
    odometer_km: amount(1, 0),
    hour_meter: amount(1, 0),
    acquisition_date: z.string().transform((value) => (value === '' ? null : value)),
    acquisition_value: amount(2, null),
    notes: text(5000),
  });
}

export type EquipmentPayload = z.output<ReturnType<typeof equipmentSchema>>;

const str = (value: unknown) => (value === null || value === undefined ? '' : String(value));

/** Valores iniciais: do registro (edição) ou os padrões da API (criação). */
export function equipmentDefaults(row: Equipment | null): EquipmentFormValues {
  return {
    code: str(row?.code),
    name: str(row?.name),
    family_id: str(row?.family?.id),
    branch_id: str(row?.branch?.id),
    cost_center_id: str(row?.cost_center?.id),
    responsible_employee_id: str(row?.responsible_employee?.id),
    plate: str(row?.plate),
    serial_number: str(row?.serial_number),
    manufacturer: str(row?.manufacturer),
    model: str(row?.model),
    year: str(row?.year),
    status: row?.status ?? 'active',
    criticality_override: str(row?.criticality_override),
    odometer_km: row ? str(row.odometer_km) : '0',
    hour_meter: row ? str(row.hour_meter) : '0',
    acquisition_date: str(row?.acquisition_date),
    acquisition_value: str(row?.acquisition_value),
    notes: str(row?.notes),
  };
}
