import { useEffect, useState } from 'react';

/** Contagem regressiva em segundos (bloqueio por 429 / Retry-After). */
export function useCountdown() {
  const [seconds, setSeconds] = useState(0);
  useEffect(() => {
    if (seconds <= 0) return;
    const timer = setTimeout(() => setSeconds((s) => s - 1), 1000);
    return () => clearTimeout(timer);
  }, [seconds]);
  return [seconds, setSeconds] as const;
}

/** "Tente novamente em N segundo(s)." */
export function retryText(seconds: number): string {
  return `Tente novamente em ${seconds} ${seconds === 1 ? 'segundo' : 'segundos'}.`;
}
