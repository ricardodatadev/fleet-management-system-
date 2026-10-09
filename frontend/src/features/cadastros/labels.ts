import type { SelectOption } from '@/components/ui';
import type { BranchType, Criticality, EquipmentCategory } from './types';

/** Rótulos pt-BR dos enums de cadastro (a API devolve só os valores). */
export const BRANCH_TYPE_LABELS: Record<BranchType, string> = {
  filial: 'Filial',
  garagem: 'Garagem',
  oficina: 'Oficina',
};

export const CATEGORY_LABELS: Record<EquipmentCategory, string> = {
  light_vehicle: 'Veículo leve',
  truck: 'Caminhão',
  agri_machine: 'Máquina agrícola',
  implement: 'Implemento',
  support: 'Apoio',
};

export const CRITICALITY_LABELS: Record<Criticality, string> = {
  low: 'Baixa',
  medium: 'Média',
  high: 'Alta',
  critical: 'Crítica',
};

/** As 27 UFs (mesma lista validada pelo backend). */
export const UFS = [
  'AC',
  'AL',
  'AP',
  'AM',
  'BA',
  'CE',
  'DF',
  'ES',
  'GO',
  'MA',
  'MT',
  'MS',
  'MG',
  'PA',
  'PB',
  'PR',
  'PE',
  'PI',
  'RJ',
  'RN',
  'RS',
  'RO',
  'RR',
  'SC',
  'SP',
  'SE',
  'TO',
] as const;

/** Opções de select a partir dos valores do enum; valor sem rótulo conhecido aparece cru. */
export function enumOptions<T extends string>(
  values: readonly T[] | undefined,
  labels: Partial<Record<T, string>>,
): SelectOption[] {
  return (values ?? []).map((value) => ({ value, label: labels[value] ?? value }));
}

export function labelOf<T extends string>(labels: Partial<Record<T, string>>, value: T): string {
  return labels[value] ?? value;
}
