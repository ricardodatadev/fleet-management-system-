import { Navigate, createBrowserRouter } from 'react-router-dom';
import type { RouteObject } from 'react-router-dom';
import { LoginPage, PublicOnly, RequireAuth, RequirePermission } from '@/features/auth';
import { DEFAULT_AUTHENTICATED_PATH } from '@/features/auth';
import { EquipmentsPage } from '@/features/equipments/EquipmentsPage';
import { SettingsPage } from '@/features/settings/SettingsPage';
import { AppLayout } from '@/layouts/AppLayout';
import { ForbiddenPage } from '@/routes/ForbiddenPage';
import { NotFoundPage } from '@/routes/NotFoundPage';
import { RouteErrorPage } from '@/routes/RouteErrorPage';

/** Rotas e guardas (spec G.2). Guardas leem `permissions` de /auth/me, nunca o nome do perfil. */
export const routes: RouteObject[] = [
  {
    errorElement: <RouteErrorPage />,
    children: [
      {
        path: '/login',
        element: (
          <PublicOnly>
            <LoginPage />
          </PublicOnly>
        ),
      },
      { path: '/403', element: <ForbiddenPage /> },
      {
        element: (
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        ),
        children: [
          { path: '/', element: <Navigate to={DEFAULT_AUTHENTICATED_PATH} replace /> },
          {
            path: '/ativos/equipamentos',
            element: (
              <RequirePermission perm="equipments.view">
                <EquipmentsPage />
              </RequirePermission>
            ),
          },
          {
            path: '/parametros',
            element: (
              <RequirePermission perm="settings.view">
                <SettingsPage />
              </RequirePermission>
            ),
          },
        ],
      },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];

export function createRouter() {
  return createBrowserRouter(routes);
}
