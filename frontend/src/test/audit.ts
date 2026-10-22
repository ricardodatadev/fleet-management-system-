import { HttpResponse, http } from 'msw';
import type { AuditLog } from '@/features/audit/types';
import { API, fail, ok } from './auth';
import { server } from './server';

/** Evento de auditoria com padrões neutros; o teste sobrescreve o que importa. */
export function auditLog(id: number, over: Partial<AuditLog> = {}): AuditLog {
  return {
    id,
    uuid: `00000000-0000-4000-8000-${String(id).padStart(12, '0')}`,
    event_at: '2026-10-20T13:00:00.000000Z',
    actor: { id: 1, name: 'Ana Souza', role: 'admin' },
    action: 'updated',
    auditable: { type: 'cost_center', id: 7 },
    old_values: { name: 'Antigo' },
    new_values: { name: 'Novo' },
    metadata: null,
    ip: '172.18.0.1',
    request_id: null,
    ...over,
  };
}

/** Usuários do combobox de ator (`/users?q=`) e do rótulo do deep-link (`/users/{id}`). */
export const AUDIT_USERS = [
  { id: 1, name: 'Ana Souza', username: 'anasouza' },
  { id: 12, name: 'Caio Mecânico', username: 'caio' },
];

/**
 * API de auditoria em memória, com as regras do contrato (F1-19): filtros exatos, período
 * inclusivo sobre `event_at` (`from` > `to` → 422), ordem fixa event_at desc, id desc, paginação e
 * show (404). `requests` guarda os parâmetros de cada lista recebida.
 */
export function mockAuditApi(seed: AuditLog[]) {
  const requests: URLSearchParams[] = [];
  const time = (value: string) => new Date(value).getTime();
  server.use(
    http.get(API('/audit-logs'), ({ request }) => {
      const params = new URL(request.url).searchParams;
      requests.push(params);
      const from = params.get('from');
      const to = params.get('to');
      if (from && to && time(from) > time(to)) {
        return fail(422, 'Os dados informados são inválidos.', {
          to: ['O campo até deve ser uma data posterior ou igual a de.'],
        });
      }
      const rows = seed
        .filter((log) => {
          const type = params.get('auditable_type');
          const id = params.get('auditable_id');
          const actor = params.get('actor_id');
          const action = params.get('action');
          if (type && log.auditable?.type !== type) return false;
          if (id && String(log.auditable?.id) !== id) return false;
          if (actor && String(log.actor?.id) !== actor) return false;
          if (action && log.action !== action) return false;
          if (from && time(log.event_at) < time(from)) return false;
          if (to && time(log.event_at) > time(to)) return false;
          return true;
        })
        .sort((a, b) => time(b.event_at) - time(a.event_at) || b.id - a.id);
      const perPage = Number(params.get('per_page')) || 15;
      const page = Number(params.get('page')) || 1;
      return HttpResponse.json({
        status: 'success',
        message: 'OK',
        errors: null,
        data: rows.slice((page - 1) * perPage, page * perPage),
        meta: {
          current_page: page,
          per_page: perPage,
          total: rows.length,
          last_page: Math.max(1, Math.ceil(rows.length / perPage)),
        },
      });
    }),
    http.get(API('/audit-logs/:id'), ({ params }) => {
      const log = seed.find((item) => String(item.id) === params.id);
      return log ? ok(log) : fail(404, 'Registro não encontrado.');
    }),
    http.get(API('/users'), ({ request }) => {
      const q = (new URL(request.url).searchParams.get('q') ?? '').toLowerCase();
      const data = AUDIT_USERS.filter((u) => u.name.toLowerCase().includes(q));
      return HttpResponse.json({
        status: 'success',
        message: 'OK',
        errors: null,
        data,
        meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1 },
      });
    }),
    http.get(API('/users/:id'), ({ params }) => {
      const found = AUDIT_USERS.find((u) => String(u.id) === params.id);
      return found ? ok(found) : fail(404, 'Registro não encontrado.');
    }),
  );
  function last(): URLSearchParams {
    const params = requests[requests.length - 1];
    if (!params) throw new Error('Nenhuma chamada a /audit-logs.');
    return params;
  }
  return { requests, last };
}
