import { Navigate, createBrowserRouter } from 'react-router-dom';
import type { RouteObject } from 'react-router-dom';
import {
  ForgotPasswordPage,
  LoginPage,
  PublicOnly,
  RequireAuth,
  RequirePermission,
  ResetPasswordPage,
} from '@/features/auth';
import { DEFAULT_AUTHENTICATED_PATH } from '@/features/auth';
import { BranchesPage } from '@/features/cadastros/BranchesPage';
import { CostCentersPage } from '@/features/cadastros/CostCentersPage';
import { EmployeesPage } from '@/features/cadastros/EmployeesPage';
import { FamiliesPage } from '@/features/cadastros/FamiliesPage';
import { UsersPage } from '@/features/cadastros/UsersPage';
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
      {
        path: '/esqueci-senha',
        element: (
          <PublicOnly>
            <ForgotPasswordPage />
          </PublicOnly>
        ),
      },
      {
        path: '/redefinir-senha',
        element: (
          <PublicOnly>
            <ResetPasswordPage />
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
            path: '/cadastros/colaboradores',
            element: (
              <RequirePermission perm="employees.view">
                <EmployeesPage />
              </RequirePermission>
            ),
          },
          {
            path: '/cadastros/unidades',
            element: (
              <RequirePermission perm="branches.view">
                <BranchesPage />
              </RequirePermission>
            ),
          },
          {
            path: '/cadastros/centros-custo',
            element: (
              <RequirePermission perm="cost_centers.view">
                <CostCentersPage />
              </RequirePermission>
            ),
          },
          {
            path: '/cadastros/familias',
            element: (
              <RequirePermission perm="equipment_families.view">
                <FamiliesPage />
              </RequirePermission>
            ),
          },
          {
            path: '/admin/usuarios',
            element: (
              <RequirePermission perm="users.view">
                <UsersPage />
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
