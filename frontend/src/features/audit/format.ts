/** Data/hora do evento em Brasília, com segundos (a ordem fina importa na auditoria). */
const dateTime = new Intl.DateTimeFormat('pt-BR', {
  dateStyle: 'short',
  timeStyle: 'medium',
  timeZone: 'America/Sao_Paulo',
});

export function formatEventAt(iso: string): string {
  return dateTime.format(new Date(iso));
}

/**
 * O filtro de período é por dia de Brasília (o que o usuário vê na tabela), não por dia UTC (que é
 * o que a API faz com data sem hora). Brasília não tem horário de verão desde 2019: -03:00 fixo.
 */
const BRASILIA_OFFSET = '-03:00';

export function periodStart(date: string): string {
  return `${date}T00:00:00${BRASILIA_OFFSET}`;
}

export function periodEnd(date: string): string {
  return `${date}T23:59:59.999999${BRASILIA_OFFSET}`;
}
