import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { AuthContext, useAuth, useCan } from './auth-context';
import type { AuthContextValue } from './auth-context';

const actions = { login: vi.fn(), logout: vi.fn(), retry: vi.fn() };

function wrapper(value: AuthContextValue) {
  return ({ children }: { children: ReactNode }) => (
    <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
  );
}

describe('useCan', () => {
  it('responde pela lista de permissions de /auth/me', () => {
    const { result } = renderHook(() => useCan(), {
      wrapper: wrapper({
        ...actions,
        status: 'authenticated',
        user: { id: 1, name: 'A', username: 'aaa', email: 'a@x', role: 'leader', branch: null },
        permissions: ['settings.view'],
        expiresAt: '2099-01-01T00:00:00Z',
      }),
    });
    expect(result.current('settings.view')).toBe(true);
    expect(result.current('settings.manage')).toBe(false);
  });

  it('sem sessão nada é permitido', () => {
    const { result } = renderHook(() => useCan(), {
      wrapper: wrapper({ ...actions, status: 'anonymous', reason: 'initial' }),
    });
    expect(result.current('equipments.view')).toBe(false);
  });

  it('fora do provider lança erro', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => renderHook(() => useAuth())).toThrow(/AuthProvider/);
    spy.mockRestore();
  });
});
