/** Perfis (spec E / AuthUser.role da OpenAPI). */
export type Role = 'operator' | 'mechanic' | 'leader' | 'admin';

export interface AuthBranch {
  id: number;
  code: string;
  name: string;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: Role;
  /** null = todas as filiais (somente admin). */
  branch: AuthBranch | null;
}

/** POST /auth/login → data. */
export interface LoginData {
  token: string;
  token_type: 'Bearer';
  /** ISO-8601 UTC. */
  expires_at: string;
  user: AuthUser;
}

/** GET /auth/me → data. */
export interface MeData {
  user: AuthUser;
  employee: Record<string, unknown> | null;
  /** Permissões do perfil (config/rbac.php); `auth.*` é implícito e não aparece. */
  permissions: string[];
}

export interface LoginCredentials {
  email: string;
  password: string;
}
