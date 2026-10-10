import { pathToFileURL } from 'node:url';
import Fastify, { type FastifyInstance } from 'fastify';
import { Redis } from 'ioredis';
import { Server } from 'socket.io';
import { authenticateSockets } from './auth.js';
import { checkRedis, type Pingable } from './health.js';

const SERVICE_NAME = 'node-realtime';

export interface ServerDeps {
  redis: Pingable;
  healthTimeoutMs?: number;
  /** Base interna do Laravel para validar o token do handshake (LARAVEL_INTERNAL_URL). */
  laravelUrl?: string;
  /** Timeout da validação do token; padrão 2 s (AUTH_TIMEOUT_MS). */
  authTimeoutMs?: number;
}

export function buildServer({
  redis,
  healthTimeoutMs = 1000,
  laravelUrl = process.env.LARAVEL_INTERNAL_URL ?? 'http://nginx',
  authTimeoutMs,
}: ServerDeps): { app: FastifyInstance; io: Server } {
  const app = Fastify({ logger: process.env.NODE_ENV !== 'test' });

  app.get('/health', async (_request, reply) => {
    const up = await checkRedis(redis, healthTimeoutMs);
    return reply
      .code(up ? 200 : 503)
      .send({ status: up ? 'ok' : 'error', service: SERVICE_NAME, redis: up ? 'up' : 'down' });
  });

  const io = new Server(app.server, { path: '/socket.io/', serveClient: false });
  authenticateSockets(io, { laravelUrl, timeoutMs: authTimeoutMs });

  app.addHook('onClose', async () => {
    await io.close();
  });

  return { app, io };
}

async function main(): Promise<void> {
  const redis = new Redis({
    host: process.env.REDIS_HOST ?? 'redis',
    port: Number(process.env.REDIS_PORT ?? 6379),
    password: process.env.REDIS_PASSWORD,
    connectTimeout: 2000,
    maxRetriesPerRequest: 1,
    enableOfflineQueue: false,
    retryStrategy: (times) => Math.min(times * 200, 2000),
  });
  let lastError = 0;
  redis.on('error', (err: Error) => {
    if (Date.now() - lastError > 10_000) {
      lastError = Date.now();
      console.error(`redis error: ${err.message}`);
    }
  });

  const { app } = buildServer({ redis });
  const port = Number(process.env.NODE_PORT ?? 3000);
  await app.listen({ port, host: '0.0.0.0' });

  let closing = false;
  const shutdown = async (signal: string): Promise<void> => {
    if (closing) return;
    closing = true;
    app.log.info(`${signal} recebido, encerrando`);
    try {
      await app.close();
      redis.disconnect();
      process.exit(0);
    } catch (err) {
      console.error(err);
      process.exit(1);
    }
  };
  process.on('SIGTERM', () => void shutdown('SIGTERM'));
  process.on('SIGINT', () => void shutdown('SIGINT'));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
