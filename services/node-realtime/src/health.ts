export interface Pingable {
  ping(): Promise<string>;
}

/** Verifica o Redis com timeout curto, sem travar o handler. */
export async function checkRedis(redis: Pingable, timeoutMs = 1000): Promise<boolean> {
  let timer: NodeJS.Timeout | undefined;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new Error('redis ping timeout')), timeoutMs);
  });
  try {
    const reply = await Promise.race([redis.ping(), timeout]);
    return reply === 'PONG';
  } catch {
    return false;
  } finally {
    clearTimeout(timer);
  }
}
