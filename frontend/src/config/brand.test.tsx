import { afterEach, describe, expect, it, vi } from 'vitest';
import { BRAND_DEFAULTS } from './brand';

const CUSTOM = { name: 'Frota X', fullName: 'Frota X Operações', slug: 'frota-x' };

/** Recarrega o grafo de módulos para que `brand.ts` releia `import.meta.env`. */
async function loadWithEnv(values: Partial<typeof CUSTOM>) {
  vi.stubEnv('VITE_APP_NAME', values.name ?? '');
  vi.stubEnv('VITE_APP_FULL_NAME', values.fullName ?? '');
  vi.stubEnv('VITE_APP_SLUG', values.slug ?? '');
  vi.resetModules();
  return import('./brand');
}

describe('brand (F1-33)', () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  it('lê os valores das VITE_APP_*', async () => {
    const brand = await loadWithEnv(CUSTOM);
    expect([brand.APP_NAME, brand.APP_FULL_NAME, brand.APP_SLUG]).toEqual([
      CUSTOM.name,
      CUSTOM.fullName,
      CUSTOM.slug,
    ]);
  });

  it('cai nos defaults quando as VITE_APP_* vêm vazias', async () => {
    const brand = await loadWithEnv({});
    expect([brand.APP_NAME, brand.APP_FULL_NAME, brand.APP_SLUG]).toEqual([
      BRAND_DEFAULTS.name,
      BRAND_DEFAULTS.fullName,
      BRAND_DEFAULTS.slug,
    ]);
  });

  it('chave de sessão e device name derivam do slug', async () => {
    await loadWithEnv(CUSTOM);
    const { SESSION_STORAGE_KEY } = await import('@/features/auth/session');
    const { DEVICE_NAME } = await import('@/features/auth/api');
    expect(SESSION_STORAGE_KEY).toBe('frota-x.session');
    expect(DEVICE_NAME).toBe('frota-x-web');
  });

  it('trocar as VITE_APP_* muda o nome exibido no login e na topbar', async () => {
    await loadWithEnv(CUSTOM);
    // Imports frescos (mesma instância de React do grafo recarregado).
    const { cleanup, render, screen } = await import('@testing-library/react');
    const { renderApp } = await import('@/test/auth');
    const { Topbar } = await import('@/layouts/Topbar');

    renderApp('/login');
    expect(await screen.findByText(CUSTOM.name)).toBeInTheDocument();
    expect(screen.getByText(CUSTOM.fullName)).toBeInTheDocument();
    expect(screen.queryByText(BRAND_DEFAULTS.name)).not.toBeInTheDocument();
    cleanup();

    const user = {
      id: 1,
      name: 'Ana Souza',
      email: 'ana@example.com',
      role: 'admin',
      branch: null,
    } as const;
    render(<Topbar user={user} onLogout={() => {}} />);
    expect(screen.getByRole('banner', { name: 'Barra superior' })).toHaveTextContent(CUSTOM.name);
    cleanup();
  });
});
