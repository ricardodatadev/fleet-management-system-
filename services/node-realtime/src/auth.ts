import type { Server, Socket } from 'socket.io';

/** Usuário da sessão, como devolvido por GET /api/v1/auth/me (só o necessário para as salas). */
export interface SessionUser {
  id: number;
  name: string;
  username: string;
  role: string;
  branch: { id: number; code: string; name: string } | null;
}

export interface AuthOptions {
  /** Base interna do Laravel (LARAVEL_INTERNAL_URL), sem barra no fim. */
  laravelUrl: string;
  /** Tempo máximo da consulta ao /auth/me; estourou → recusa (fail-closed). */
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
}

/**
 * Timeout da consulta ao /auth/me. 1,8 s para a recusa chegar ao CLIENTE em ≤ 2 s (CA da F1-18), já
 * contando o handshake e a ida e volta do connect_error.
 */
export const AUTH_TIMEOUT_MS = 1800;

/** Único erro exposto ao cliente: nada sobre o motivo nem eco do token. */
export const UNAUTHORIZED = 'unauthorized';

/** Tokens Sanctum são curtos ("<id>|<40 chars>"); algo muito maior é recusado sem consultar o Laravel. */
const MAX_TOKEN_LENGTH = 512;

/**
 * Handshake autenticado (F1-18): lê `handshake.auth.token`, valida com GET /api/v1/auth/me (Bearer,
 * timeout de 1,8 s) e, se ok, guarda o usuário em `socket.data.user`, entra nas salas `user:{id}`,
 * `role:{role}` e `branch:{id}` (só quando o usuário tem filial; admin sem filial não entra em sala de
 * filial) e emite `session:ready`. Qualquer falha (sem token, 401/403, resposta inválida, timeout,
 * Laravel fora) → `connect_error` com a mensagem `unauthorized`. O token nunca é logado nem ecoado.
 * Sem consumo de eventos de OS na Fase 1.
 */
export function authenticateSockets(io: Server, options: AuthOptions): void {
  const timeoutMs = options.timeoutMs ?? AUTH_TIMEOUT_MS;
  const fetchImpl = options.fetchImpl ?? fetch;
  const meUrl = `${options.laravelUrl.replace(/\/+$/, '')}/api/v1/auth/me`;

  io.use((socket, next) => {
    const token: unknown = socket.handshake.auth?.token;
    if (typeof token !== 'string' || token.trim() === '' || token.length > MAX_TOKEN_LENGTH) {
      next(new Error(UNAUTHORIZED));
      return;
    }
    fetchSessionUser(fetchImpl, meUrl, token, timeoutMs)
      .then((user) => {
        if (user === null) {
          next(new Error(UNAUTHORIZED));
          return;
        }
        socket.data.user = user;
        next();
      })
      .catch(() => next(new Error(UNAUTHORIZED)));
  });

  io.on('connection', (socket: Socket) => {
    const user = socket.data.user as SessionUser;
    const rooms = roomsFor(user);
    void socket.join(rooms);
    socket.emit('session:ready', {
      user: { id: user.id, name: user.name, username: user.username, role: user.role, branch_id: user.branch?.id ?? null },
      rooms,
    });
  });
}

/** Salas do usuário: sempre `user:{id}` e `role:{role}`; `branch:{id}` só com filial. */
export function roomsFor(user: SessionUser): string[] {
  const rooms = [`user:${user.id}`, `role:${user.role}`];
  if (user.branch !== null) {
    rooms.push(`branch:${user.branch.id}`);
  }
  return rooms;
}

/** null = credencial recusada ou resposta fora do contrato; lança em timeout/erro de rede. */
async function fetchSessionUser(fetchImpl: typeof fetch, url: string, token: string, timeoutMs: number): Promise<SessionUser | null> {
  const response = await fetchImpl(url, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    signal: AbortSignal.timeout(timeoutMs),
    redirect: 'error',
  });
  if (response.status !== 200) {
    await response.body?.cancel();
    return null;
  }
  const body: unknown = await response.json();
  return parseUser(body);
}

function parseUser(body: unknown): SessionUser | null {
  const user = (body as { data?: { user?: Record<string, unknown> } })?.data?.user;
  if (!user || typeof user.id !== 'number' || typeof user.role !== 'string' || user.role === '') {
    return null;
  }
  const branch = user.branch as { id?: unknown; code?: unknown; name?: unknown } | null | undefined;
  return {
    id: user.id,
    name: typeof user.name === 'string' ? user.name : '',
    username: typeof user.username === 'string' ? user.username : '',
    role: user.role,
    branch:
      branch && typeof branch.id === 'number'
        ? { id: branch.id, code: String(branch.code ?? ''), name: String(branch.name ?? '') }
        : null,
  };
}
