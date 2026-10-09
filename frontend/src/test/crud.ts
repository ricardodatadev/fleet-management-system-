import { HttpResponse, http } from 'msw';
import type { FieldErrors } from '@/api';
import { API, fail, ok } from './auth';
import { server } from './server';

export interface FakeRecord {
  id: number;
  name: string;
  is_active: boolean;
  deleted_at: string | null;
}

export interface MockCrudOptions<T extends FakeRecord> {
  /** Campos pesquisados por `q` (contém, sem diferenciar maiúsculas). */
  searchFields?: (keyof T)[];
  /** Filtros planos aceitos (igualdade; `is_active` aceita 1/0; lista CSV = qualquer um deles). */
  filters?: string[];
  /** Campo único entre não excluídos (422 se repetido) e como normalizá-lo. Padrão: `code`, maiúsculas. */
  unique?: { field: string; normalize: (value: string) => string; message?: string };
  /** Ordenação padrão do recurso (sem `sort`). Padrão: `code`. */
  defaultSort?: string;
  /** Whitelist de `sort` do recurso; campo fora dela → 422, como na API. */
  sortable: string[];
  /** Monta o registro a partir do payload (create) ou do registro atual + payload (update). */
  build: (body: Record<string, unknown>, id: number, current?: T) => T;
  /** Validação extra (422) além de código duplicado. */
  validate?: (body: Record<string, unknown>) => FieldErrors | null;
}

/** Corpo do 409 de exclusão por dependentes (D.2 / CrudActions). */
export interface Conflict {
  message: string;
  dependents: string[];
}

export interface CrudFake<T extends FakeRecord> {
  records: T[];
  /** URLs das requisições GET de lista, na ordem. */
  listRequests: URL[];
  /** Corpos de POST/PUT, na ordem. */
  writes: { method: string; id?: number; body: Record<string, unknown> }[];
  /** Exclusão do id → 409 com esta mensagem. */
  deleteConflicts: Map<number, Conflict>;
  /** Faz as listas falharem com o status dado até `failLists(null)`. */
  failLists: (failure: { status: number; message: string } | null) => void;
  lastListParams: () => URLSearchParams;
}

const DUPLICATE_CODE = 'O código já está em uso.';

/**
 * CRUD em memória para um recurso da D.2 via MSW: lista (q, filtros, sort, paginação,
 * with_trashed), create/update com 422 de código duplicado, delete com 409 configurável e restore.
 */
export function mockCrudApi<T extends FakeRecord>(
  endpoint: string,
  seed: T[],
  options: MockCrudOptions<T>,
): CrudFake<T> {
  const fake: CrudFake<T> = {
    records: seed.map((record) => ({ ...record })),
    listRequests: [],
    writes: [],
    deleteConflicts: new Map(),
    failLists: () => {},
    lastListParams: () => fake.listRequests.at(-1)?.searchParams ?? new URLSearchParams(),
  };
  let listFailure: { status: number; message: string } | null = null;
  fake.failLists = (failure) => {
    listFailure = failure;
  };
  const searchFields = options.searchFields ?? (['code', 'name'] as (keyof T)[]);
  const unique = options.unique ?? {
    field: 'code',
    normalize: (value: string) => value.trim().toUpperCase(),
  };
  const uniqueOf = (record: T) => (record as unknown as Record<string, unknown>)[unique.field];
  const byId = (id: unknown) => fake.records.find((record) => record.id === Number(id));
  const codeTaken = (code: unknown, ignoreId?: number) =>
    typeof code === 'string' &&
    fake.records.some(
      (r) => !r.deleted_at && r.id !== ignoreId && uniqueOf(r) === unique.normalize(code),
    );

  function write(body: Record<string, unknown>, current?: T) {
    const errors: FieldErrors = { ...options.validate?.(body) };
    const key = unique.field;
    if (key in body && codeTaken(body[key], current?.id)) {
      errors[key] = [unique.message ?? DUPLICATE_CODE];
    }
    if (Object.keys(errors).length > 0) {
      return fail(422, 'Os dados informados são inválidos.', errors);
    }
    const id = current?.id ?? Math.max(0, ...fake.records.map((r) => r.id)) + 1;
    const raw = body[unique.field];
    const normalized = typeof raw === 'string' ? { [unique.field]: unique.normalize(raw) } : {};
    const record = options.build({ ...body, ...normalized }, id, current);
    if (current) fake.records[fake.records.indexOf(current)] = record;
    else fake.records.push(record);
    return HttpResponse.json(
      { status: 'success', message: 'OK', errors: null, data: record },
      { status: current ? 200 : 201 },
    );
  }

  server.use(
    http.get(API(endpoint), ({ request }) => {
      const url = new URL(request.url);
      fake.listRequests.push(url);
      if (listFailure) return fail(listFailure.status, listFailure.message);
      const params = url.searchParams;
      let rows = fake.records.filter((r) => params.get('with_trashed') === '1' || !r.deleted_at);
      const q = params.get('q')?.toLowerCase();
      if (q) {
        rows = rows.filter((r) =>
          searchFields.some((field) =>
            String(r[field] ?? '')
              .toLowerCase()
              .includes(q),
          ),
        );
      }
      for (const name of options.filters ?? []) {
        const value = params.get(name);
        if (value === null) continue;
        const accepted = value.split(',');
        rows = rows.filter((r) => {
          const field = (r as unknown as Record<string, unknown>)[name];
          if (typeof field === 'boolean') return field === (value === '1');
          return accepted.includes(String(field));
        });
      }
      const sort = params.get('sort') ?? options.defaultSort ?? 'code';
      if (!options.sortable.includes(sort.replace(/^-/, ''))) {
        return fail(422, 'Os dados informados são inválidos.', {
          sort: ['A ordenação informada é inválida.'],
        });
      }
      const desc = sort.startsWith('-');
      const key = (desc ? sort.slice(1) : sort) as keyof T;
      rows = [...rows].sort((a, b) => {
        const result = String(a[key]).localeCompare(String(b[key]), 'pt-BR');
        return desc ? -result : result;
      });
      const perPage = Number(params.get('per_page') ?? 15);
      const page = Number(params.get('page') ?? 1);
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
    http.post(API(endpoint), async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      fake.writes.push({ method: 'POST', body });
      return write(body);
    }),
    http.put(API(`${endpoint}/:id`), async ({ request, params }) => {
      const body = (await request.json()) as Record<string, unknown>;
      const current = byId(params.id);
      fake.writes.push({ method: 'PUT', id: Number(params.id), body });
      if (!current || current.deleted_at) return fail(404, 'Registro não encontrado.');
      return write(body, current);
    }),
    http.delete(API(`${endpoint}/:id`), ({ params }) => {
      const current = byId(params.id);
      if (!current || current.deleted_at) return fail(404, 'Registro não encontrado.');
      const conflict = fake.deleteConflicts.get(current.id);
      if (conflict) {
        return fail(409, conflict.message, { dependents: conflict.dependents });
      }
      current.deleted_at = new Date().toISOString();
      return ok(null, 'Registro excluído.');
    }),
    http.post(API(`${endpoint}/:id/restore`), ({ params }) => {
      const current = byId(params.id);
      if (!current) return fail(404, 'Registro não encontrado.');
      if (!current.deleted_at || codeTaken(uniqueOf(current), current.id)) {
        return fail(409, 'Não é possível restaurar: o código já está em uso.');
      }
      current.deleted_at = null;
      return ok(current, 'Registro restaurado.');
    }),
  );
  return fake;
}

/** `/meta/enums` com os enums de cadastro (schema da operação `metaEnums`). */
export function mockMetaEnums() {
  server.use(
    http.get(API('/meta/enums'), () =>
      ok({
        enums: {
          branch_types: ['filial', 'garagem', 'oficina'],
          equipment_categories: ['light_vehicle', 'truck', 'agri_machine', 'implement', 'support'],
          criticalities: ['low', 'medium', 'high', 'critical'],
          roles: ['operator', 'mechanic', 'leader', 'admin'],
          job_types: ['driver', 'mechanic', 'leader', 'admin_staff'],
          cnh_categories: ['A', 'B', 'C', 'D', 'E', 'AB', 'AC', 'AD', 'AE'],
        },
      }),
    ),
  );
}

const TIMESTAMPS = { created_at: null, updated_at: null, deleted_at: null };

export function branch(id: number, code: string, name: string, over: Record<string, unknown> = {}) {
  return {
    id,
    code,
    name,
    type: 'filial' as const,
    city: null as string | null,
    state: null as string | null,
    is_active: true,
    ...TIMESTAMPS,
    ...over,
  };
}
