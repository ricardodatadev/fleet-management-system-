import type { EquipmentStatus } from './types';

export const STATUS_LABELS: Record<EquipmentStatus, string> = {
  active: 'Ativo',
  inactive: 'Inativo',
  disposed: 'Baixado',
};
