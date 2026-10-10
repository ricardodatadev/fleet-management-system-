/** Data sem hora (yyyy-mm-dd) em dd/mm/aaaa, sem passar por fuso (evita voltar um dia). */
export function formatDate(value: string | null): string {
  if (!value) return '—';
  const [year, month, day] = value.slice(0, 10).split('-');
  return `${day}/${month}/${year}`;
}
