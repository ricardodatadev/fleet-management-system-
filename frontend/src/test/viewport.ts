/**
 * matchMedia do jsdom não existe: este stub avalia `min-width`/`max-width` contra
 * `window.innerWidth` e notifica os ouvintes em `setViewport`.
 */
type Listener = (event: MediaQueryListEvent) => void;

const listeners = new Set<{ query: string; listener: Listener }>();

function evaluate(query: string): boolean {
  const width = window.innerWidth;
  const min = /min-width:\s*(\d+)px/.exec(query);
  const max = /max-width:\s*(\d+)px/.exec(query);
  return (!min || width >= Number(min[1])) && (!max || width <= Number(max[1]));
}

export function installMatchMedia(): void {
  window.matchMedia = (query: string) =>
    ({
      media: query,
      get matches() {
        return evaluate(query);
      },
      onchange: null,
      addEventListener: (_type: string, listener: Listener) => {
        listeners.add({ query, listener });
      },
      removeEventListener: (_type: string, listener: Listener) => {
        for (const entry of listeners) if (entry.listener === listener) listeners.delete(entry);
      },
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
    }) as unknown as MediaQueryList;
}

export function setViewport(width: number): void {
  window.innerWidth = width;
  for (const { query, listener } of [...listeners]) {
    listener({ matches: evaluate(query), media: query } as MediaQueryListEvent);
  }
}

/** Largura padrão dos testes: desktop (≥1024). */
export const DEFAULT_VIEWPORT = 1280;
