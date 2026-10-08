import type { AddressInfo } from 'node:net';
import { afterEach, describe, expect, it } from 'vitest';
import { io as client, type Socket } from 'socket.io-client';
import { buildServer } from '../src/server.js';

let sock: Socket | undefined;
let closeApp: (() => Promise<unknown>) | undefined;
afterEach(async () => {
  sock?.close();
  await closeApp?.();
});

describe('Socket.io (sem handshake autenticado até a F1-18)', () => {
  it('recusa toda conexão com connect_error not_implemented', async () => {
    const { app } = buildServer({ redis: { ping: async () => 'PONG' } });
    closeApp = () => app.close();
    await app.listen({ port: 0, host: '127.0.0.1' });
    const { port } = app.server.address() as AddressInfo;

    sock = client(`http://127.0.0.1:${port}`, { transports: ['websocket'], reconnection: false });
    const message = await new Promise<string>((resolve, reject) => {
      sock!.on('connect', () => reject(new Error('conectou sem auth')));
      sock!.on('connect_error', (err) => resolve(err.message));
    });
    expect(message).toBe('not_implemented');
  });
});
