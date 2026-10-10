import { useCallback, useSyncExternalStore } from 'react';

/** Estado de uma media query, atualizado em `change`. Sem matchMedia (SSR/teste) = false. */
export function useMediaQuery(query: string): boolean {
  const subscribe = useCallback(
    (onChange: () => void) => {
      if (typeof window.matchMedia !== 'function') return () => {};
      const mql = window.matchMedia(query);
      mql.addEventListener('change', onChange);
      return () => mql.removeEventListener('change', onChange);
    },
    [query],
  );
  const getSnapshot = () =>
    typeof window.matchMedia === 'function' && window.matchMedia(query).matches;
  return useSyncExternalStore(subscribe, getSnapshot, () => false);
}

export type Breakpoint = 'mobile' | 'tablet' | 'desktop';

/** Breakpoints da spec G.1: <768 drawer · 768–1023 sidebar em ícones · ≥1024 sidebar fixa. */
export function useBreakpoint(): Breakpoint {
  const desktop = useMediaQuery('(min-width: 1024px)');
  const tablet = useMediaQuery('(min-width: 768px)');
  if (desktop) return 'desktop';
  return tablet ? 'tablet' : 'mobile';
}
