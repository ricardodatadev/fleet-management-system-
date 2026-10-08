import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildServer } from '../src/server.js';

const open: Array<{ close: () => Promise<unknown> }> = [];
afterEach(async () => {
  while (open.length) await open.pop()!.close();
});

function make(ping: () => Promise<string>, healthTimeoutMs = 50) {
  const { app, io } = buildServer({ redis: { ping }, healthTimeoutMs });
  open.push(app);
  return { app, io };
}

describe('GET /health', () => {
  it('200 com redis up', async () => {
    const { app } = make(vi.fn().mockResolvedValue('PONG'));
    const res = await app.inject({ method: 'GET', url: '/health' });
    expect(res.statusCode).toBe(200);
    expect(res.json()).toEqual({ status: 'ok', service: 'node-realtime', redis: 'up' });
  });

  it('503 com redis down (erro)', async () => {
    const { app } = make(vi.fn().mockRejectedValue(new Error('ECONNREFUSED')));
    const res = await app.inject({ method: 'GET', url: '/health' });
    expect(res.statusCode).toBe(503);
    expect(res.json()).toEqual({ status: 'error', service: 'node-realtime', redis: 'down' });
  });

  it('503 quando o ping excede o timeout', async () => {
    const { app } = make(() => new Promise<string>(() => {}), 30);
    const res = await app.inject({ method: 'GET', url: '/health' });
    expect(res.statusCode).toBe(503);
    expect(res.json().redis).toBe('down');
  });
});
