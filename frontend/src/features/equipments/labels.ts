import type { EquipmentStatus } from './types';

export const STATUS_LABELS: Record<EquipmentStatus, string> = {
  active: 'Ativo',
  inactive: 'Inativo',
  disposed: 'Baixado',
};

/** Aviso dos botões de escrita até o formulário por abas (F1-26) existir. */
export const FORM_SOON = 'O cadastro e a edição de frotas chegam na próxima entrega.';
