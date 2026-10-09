import { QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { RouterProvider, createMemoryRouter } from 'react-router-dom';
import { createQueryClient } from '@/app/queryClient';
import { routes } from '@/app/router';
import { ToastProvider } from '@/components/ui';
import { AuthProvider } from '@/features/auth';
import type { AuthUser, Role } from '@/features/auth';
import { SESSION_STORAGE_KEY } from '@/features/auth/session';
import { server } from './server';

/** Espelho de backend/config/rbac.php (matriz da spec E). */
export const PERMISSIONS: Record<Role, string[]> = {
  operator: ['branches.view', 'equipments.view'],
  mechanic: ['branches.view', 'equipment_families.view', 'equipments.view'],
  leader: [
    'branches.view',
    'cost_centers.view',
    'equipment_families.view',
    'equipments.view',
    'employees.view',
    'settings.view',
  ],
  admin: [
    'branches.view',
    'branches.manage',
    'cost_centers.view',
    'cost_centers.manage',
    'equipment_families.view',
    'equipment_families.manage',
    'equipments.view',
    'equipments.manage',
    'employees.view',
    'employees.manage',
    'users.view',
    'users.manage',
    'settings.view',
    'settings.manage',
    'audit.view',
  ],
};

const MATRIZ = { id: 1, code: 'FIL-001', name: 'Matriz' };

export function makeUser(role: Role = 'admin'): AuthUser {
  return {
    id: 1,
    name: 'Ana Souza',
    email: 'ana@example.com',
    role,
    branch: role === 'admin' ? null : MATRIZ,
  };
}

export const API = (path: string) => `/api/v1${path}`;

export const ok = (data: unknown, message = 'OK') =>
  HttpResponse.json({ status: 'success', message, errors: null, data });

export const fail = (
  status: number,
  message: string,
  errors: Record<string, string[]> | null = null,
  headers?: Record<string, string>,
) => HttpResponse.json({ status: 'error', message, errors, data: null }, { status, headers });

export const futureIso = (ms = 12 * 3600_000) => new Date(Date.now() + ms).toISOString();

/** Handlers de auth: login aceita `senha-correta`, /me responde conforme o perfil. */
export function mockAuthApi(role: Role = 'admin', opts: { expiresAt?: string } = {}) {
  const user = makeUser(role);
  server.use(
    http.post(API('/auth/login'), async ({ request }) => {
      const body = (await request.json()) as { password?: string };
      if (body.password !== 'senha-correta') {
        return fail(422, 'Os dados informados são inválidos.', {
          email: ['Credenciais inválidas.'],
        });
      }
      return ok({
        token: 'tok-novo',
        token_type: 'Bearer',
        expires_at: opts.expiresAt ?? futureIso(),
        user,
      });
    }),
    http.get(API('/auth/me'), ({ request }) =>
      request.headers.get('Authorization')
        ? ok({ user, employee: null, permissions: PERMISSIONS[role] })
        : fail(401, 'Não autenticado.'),
    ),
    http.post(API('/auth/logout'), () => ok(null, 'Sessão encerrada.')),
  );
  return user;
}

export function storeSession(token = 'tok-salvo', expiresAt = futureIso()) {
  localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({ token, expiresAt }));
}

export function storedSession(): unknown {
  const raw = localStorage.getItem(SESSION_STORAGE_KEY);
  return raw ? JSON.parse(raw) : null;
}

export function renderApp(initialPath = '/') {
  const router = createMemoryRouter(routes, { initialEntries: [initialPath] });
  const queryClient = createQueryClient();
  const view = render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </AuthProvider>
    </QueryClientProvider>,
  );
  const location = () => `${router.state.location.pathname}${router.state.location.search}`;
  return { ...view, router, location, queryClient };
}
