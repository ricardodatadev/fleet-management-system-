import { HttpResponse, http } from 'msw';
import { API, fail, ok } from './auth';
import { server } from './server';

type ScopeType = 'global' | 'branch' | 'family';
type Value = string | boolean;

/** Registry da F.2 (mesmos tipos, defaults e escopos do backend). */
export const DEFINITIONS = [
  {
    key: 'preventive.dispatch_mode',
    rule: 'RN-001',
    label: 'Disparo da preventiva',
    description: 'Gerar a OS preventiva automaticamente ou só sugerir.',
    type: 'enum',
    values: [
      { value: 'automatic', label: 'Automático' },
      { value: 'suggestion', label: 'Sugestão' },
    ],
    default: 'suggestion',
    scopes: ['global', 'family'],
    placeholder: false,
  },
  {
    key: 'workorder.block_close_without_labor',
    rule: 'RN-002',
    label: 'Bloquear fechamento de OS sem apontamento',
    description: 'Exigir horas apontadas para fechar a OS.',
    type: 'bool',
    values: null,
    default: false,
    scopes: ['global', 'branch'],
    placeholder: true,
  },
  {
    key: 'stock.allow_issue_with_fiscal_pending',
    rule: 'RN-003',
    label: 'Permitir baixa com pendência fiscal',
    description: 'Liberar a saída de peça com nota fiscal pendente.',
    type: 'bool',
    values: null,
    default: true,
    scopes: ['global', 'branch'],
    placeholder: false,
  },
  {
    key: 'warranty.alert_mode',
    rule: 'RN-004',
    label: 'Tratamento de peça ou serviço em garantia',
    description: 'Só alertar ou bloquear quando houver garantia vigente.',
    type: 'enum',
    values: [
      { value: 'warning', label: 'Apenas alertar' },
      { value: 'hard_block', label: 'Bloquear' },
    ],
    default: 'warning',
    scopes: ['global', 'branch', 'family'],
    placeholder: false,
  },
] as const;

export const SAVED = 'Parâmetro salvo. A alteração foi registrada em auditoria.';
export const NO_CHANGE = 'Nenhuma alteração: o parâmetro já tinha esse valor.';

interface Override {
  key: string;
  scope_type: ScopeType;
  scope_id: number | null;
  value: Value;
}

export interface SettingsFake {
  overrides: Override[];
  effectiveRequests: URL[];
  writes: {
    method: 'PUT' | 'DELETE';
    key: string;
    body?: unknown;
    query?: Record<string, string>;
  }[];
}

/**
 * API de settings em memória (F1-16): resolução family > branch > global > default entre os escopos
 * permitidos da chave, PUT idempotente, DELETE (global → 422; inexistente → 404) e o 403 do
 * líder para outra filial (`leaderBranchId`).
 */
export function mockSettingsApi(
  seed: Override[],
  options: { leaderBranchId?: number } = {},
): SettingsFake {
  const fake: SettingsFake = {
    overrides: seed.map((o) => ({ ...o })),
    effectiveRequests: [],
    writes: [],
  };
  const def = (key: string) => DEFINITIONS.find((d) => d.key === key);
  const find = (key: string, type: ScopeType, id: number | null) =>
    fake.overrides.find((o) => o.key === key && o.scope_type === type && o.scope_id === id);

  server.use(
    http.get(API('/settings/definitions'), () => ok(DEFINITIONS)),
    http.get(API('/settings/effective'), ({ request }) => {
      const url = new URL(request.url);
      fake.effectiveRequests.push(url);
      const key = url.searchParams.get('key') ?? '';
      const definition = def(key);
      if (!definition)
        return fail(422, 'Os dados informados são inválidos.', { key: ['Chave inválida.'] });
      const branchId = url.searchParams.get('branch_id');
      const familyId = url.searchParams.get('family_id');
      if (
        options.leaderBranchId !== undefined &&
        branchId &&
        Number(branchId) !== options.leaderBranchId
      ) {
        return fail(403, 'Você não tem permissão para consultar outra filial.');
      }
      const chain: [ScopeType, number | null][] = [];
      if (familyId) chain.push(['family', Number(familyId)]);
      if (branchId) chain.push(['branch', Number(branchId)]);
      chain.push(['global', null]);
      for (const [type, id] of chain) {
        if (!(definition.scopes as readonly string[]).includes(type)) continue;
        const override = find(key, type, id);
        if (override) {
          return ok({ key, value: override.value, source: { scope_type: type, scope_id: id } });
        }
      }
      return ok({ key, value: definition.default, source: 'default' });
    }),
    http.put(API('/settings/:key'), async ({ request, params }) => {
      const key = String(params.key);
      const body = (await request.json()) as {
        scope_type: ScopeType;
        scope_id: number | null;
        value: Value;
      };
      fake.writes.push({ method: 'PUT', key, body });
      const definition = def(key);
      if (!definition || !(definition.scopes as readonly string[]).includes(body.scope_type)) {
        return fail(422, 'Os dados informados são inválidos.', {
          scope_type: ['Escopo não permitido para este parâmetro.'],
        });
      }
      const existing = find(key, body.scope_type, body.scope_id);
      if (existing && existing.value === body.value) return ok(null, NO_CHANGE);
      if (existing) existing.value = body.value;
      else fake.overrides.push({ key, ...body });
      return ok(null, SAVED);
    }),
    http.delete(API('/settings/:key'), ({ request, params }) => {
      const key = String(params.key);
      const url = new URL(request.url);
      const query = Object.fromEntries(url.searchParams);
      fake.writes.push({ method: 'DELETE', key, query });
      const type = query.scope_type as ScopeType;
      if (type === 'global') {
        return fail(422, 'Os dados informados são inválidos.', {
          scope_type: ['O valor global não pode ser removido.'],
        });
      }
      const existing = find(key, type, Number(query.scope_id));
      if (!existing) return fail(404, 'Registro não encontrado.');
      fake.overrides.splice(fake.overrides.indexOf(existing), 1);
      return HttpResponse.json({
        status: 'success',
        message: 'Parâmetro removido. A alteração foi registrada em auditoria.',
        errors: null,
        data: null,
      });
    }),
  );
  return fake;
}

/** Globais do seeder + um override de filial (RN-003 na filial 1) e um de família (RN-004 na família 1). */
export const SEED: Override[] = [
  { key: 'preventive.dispatch_mode', scope_type: 'global', scope_id: null, value: 'suggestion' },
  {
    key: 'workorder.block_close_without_labor',
    scope_type: 'global',
    scope_id: null,
    value: false,
  },
  {
    key: 'stock.allow_issue_with_fiscal_pending',
    scope_type: 'global',
    scope_id: null,
    value: true,
  },
  { key: 'warranty.alert_mode', scope_type: 'global', scope_id: null, value: 'warning' },
  { key: 'stock.allow_issue_with_fiscal_pending', scope_type: 'branch', scope_id: 1, value: false },
  { key: 'warranty.alert_mode', scope_type: 'family', scope_id: 1, value: 'hard_block' },
];
