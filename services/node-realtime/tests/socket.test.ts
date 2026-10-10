import { createServer, type IncomingMessage, type Server as HttpServer, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { io as client, type Socket } from 'socket.io-client';
import { AUTH_TIMEOUT_MS, roomsFor, type SessionUser } from '../src/auth.js';
import { buildServer } from '../src/server.js';

/*
 * Laravel falso para o GET /api/v1/auth/me: o Bearer decide a resposta.
 *   valid-leader / valid-admin → 200 com o usuário; revoked → 401 (token apagado no logout);
 *   slow → responde depois de 5 s; broken → 200 sem o contrato.
 */
const USERS: Record<string, SessionUser> = {
  'valid-leader': { id: 7, name: 'Lia', username: 'lia', role: 'leader', branch: { id: 3, code: 'FIL-3', name: 'Filial 3' } },
  'valid-admin': { id: 1, name: 'Ana', username: 'ana', role: 'admin', branch: null },
};

let laravel: HttpServer;
let laravelUrl: string;
const seenAuth: string[] = [];

function fakeMe(req: IncomingMessage, res: ServerResponse): void {
  const token = (req.headers.authorization ?? '').replace(/^Bearer /, '');
  seenAuth.push(`${req.method} ${req.url} ${req.headers.authorization ? 'Bearer' : '-'}`);
  if (req.url !== '/api/v1/auth/me') {
    res.writeHead(404).end();
    return;
  }
  if (token === 'slow') {
    setTimeout(() => res.writeHead(200, { 'content-type': 'application/json' }).end('{}'), 5000);
    return;
  }
  if (token === 'broken') {
    res.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({ data: { user: { id: 'x' } } }));
    return;
  }
  const user = USERS[token];
  if (!user) {
    res.writeHead(401, { 'content-type': 'application/json' }).end(JSON.stringify({ status: 'error', message: 'Não autenticado.' }));
    return;
  }
  res.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({ status: 'success', data: { user, employee: null, permissions: [] } }));
}

beforeEach(async () => {
  seenAuth.length = 0;
  laravel = createServer(fakeMe);
  await new Promise<void>((resolve) => laravel.listen(0, '127.0.0.1', resolve));
  laravelUrl = `http://127.0.0.1:${(laravel.address() as AddressInfo).port}`;
});

let sock: Socket | undefined;
let closeApp: (() => Promise<unknown>) | undefined;
afterEach(async () => {
  sock?.close();
  await closeApp?.();
  laravel.closeAllConnections();
  await new Promise<void>((resolve) => laravel.close(() => resolve()));
  vi.restoreAllMocks();
});

async function start(url = laravelUrl, authTimeoutMs?: number) {
  const { app, io } = buildServer({ redis: { ping: async () => 'PONG' }, laravelUrl: url, authTimeoutMs });
  closeApp = () => app.close();
  await app.listen({ port: 0, host: '127.0.0.1' });
  return { io, port: (app.server.address() as AddressInfo).port };
}

type Outcome = { kind: 'ready'; payload: Record<string, unknown> } | { kind: 'error'; message: string; data: unknown };

function connect(port: number, auth?: Record<string, unknown>): Promise<Outcome> {
  sock = client(`http://127.0.0.1:${port}`, { transports: ['websocket'], reconnection: false, auth });
  return new Promise((resolve) => {
    sock!.on('session:ready', (payload) => resolve({ kind: 'ready', payload }));
    sock!.on('connect_error', (err: Error & { data?: unknown }) => resolve({ kind: 'error', message: err.message, data: err.data }));
  });
}

describe('handshake Socket.io autenticado (F1-18)', () => {
  it('token válido conecta, entra em user/role/branch e recebe session:ready com user.id', async () => {
    const { io, port } = await start();

    const outcome = await connect(port, { token: 'valid-leader' });

    expect(outcome).toEqual({
      kind: 'ready',
      payload: {
        user: { id: 7, name: 'Lia', username: 'lia', role: 'leader', branch_id: 3 },
        rooms: ['user:7', 'role:leader', 'branch:3'],
      },
    });
    const rooms = io.of('/').adapter.rooms;
    for (const room of ['user:7', 'role:leader', 'branch:3']) {
      expect(rooms.get(room)?.size).toBe(1);
    }
    const [server] = await io.fetchSockets();
    expect(server.data.user).toMatchObject({ id: 7, role: 'leader' });
    expect(seenAuth).toEqual(['GET /api/v1/auth/me Bearer']);
  });

  it('admin sem filial: salas user e role, sem sala de filial', async () => {
    const { io, port } = await start();

    const outcome = await connect(port, { token: 'valid-admin' });

    expect(outcome.kind).toBe('ready');
    expect((outcome as { payload: { rooms: string[] } }).payload.rooms).toEqual(['user:1', 'role:admin']);
    expect([...io.of('/').adapter.rooms.keys()].some((r) => r.startsWith('branch:'))).toBe(false);
  });

  it.each([
    ['sem auth', undefined],
    ['sem token', {}],
    ['token vazio', { token: '' }],
    ['token não-string', { token: 123 }],
    ['token gigante', { token: 'x'.repeat(600) }],
  ])('%s → connect_error unauthorized, sem consultar o Laravel', async (_label, auth) => {
    const { port } = await start();

    expect(await connect(port, auth)).toEqual({ kind: 'error', message: 'unauthorized', data: undefined });
    expect(seenAuth).toEqual([]);
  });

  it.each([
    ['token inválido', 'nao-existe'],
    ['token revogado (após logout)', 'revoked'],
    ['resposta fora do contrato', 'broken'],
  ])('%s → connect_error unauthorized', async (_label, token) => {
    const { port } = await start();

    expect(await connect(port, { token })).toEqual({ kind: 'error', message: 'unauthorized', data: undefined });
    expect(seenAuth).toHaveLength(1);
  });

  it('Laravel lento → recusa (fail-closed) em ≤ 2 s no cliente (timeout de 1,8 s)', async () => {
    const { port } = await start();

    const t0 = performance.now();
    const outcome = await connect(port, { token: 'slow' });
    const elapsed = performance.now() - t0;

    expect(outcome).toEqual({ kind: 'error', message: 'unauthorized', data: undefined });
    expect(elapsed).toBeGreaterThanOrEqual(AUTH_TIMEOUT_MS - 100);
    expect(elapsed).toBeLessThanOrEqual(2000);
  }, 10_000);

  it('Laravel fora do ar → recusa na hora (fail-closed)', async () => {
    const { port } = await start('http://127.0.0.1:1');

    const t0 = performance.now();
    expect(await connect(port, { token: 'valid-leader' })).toEqual({ kind: 'error', message: 'unauthorized', data: undefined });
    expect(performance.now() - t0).toBeLessThan(2000);
  });

  it('o token não aparece em log nem no erro devolvido ao cliente', async () => {
    const spies = (['log', 'info', 'warn', 'error'] as const).map((m) => vi.spyOn(console, m));
    const { port } = await start();

    const outcome = await connect(port, { token: 'segredo-que-nao-pode-vazar' });

    expect(JSON.stringify(outcome)).not.toContain('segredo');
    for (const spy of spies) {
      expect(JSON.stringify(spy.mock.calls)).not.toContain('segredo');
    }
  });
});

describe('roomsFor', () => {
  it('filial vira sala; sem filial, não', () => {
    expect(roomsFor({ id: 2, name: '', username: '', role: 'operator', branch: { id: 9, code: '', name: '' } })).toEqual(['user:2', 'role:operator', 'branch:9']);
    expect(roomsFor({ id: 1, name: '', username: '', role: 'admin', branch: null })).toEqual(['user:1', 'role:admin']);
  });
});
