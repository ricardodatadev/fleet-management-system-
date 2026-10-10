export interface SortState {
  field: string;
  direction: 'asc' | 'desc';
}

/** Próximo estado do ciclo asc → desc → sem ordenação. */
export function nextSort(current: SortState | null | undefined, field: string): SortState | null {
  if (!current || current.field !== field) return { field, direction: 'asc' };
  if (current.direction === 'asc') return { field, direction: 'desc' };
  return null;
}
