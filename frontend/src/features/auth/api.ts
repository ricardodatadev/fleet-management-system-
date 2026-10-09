import { api } from '@/api';
import type { LoginCredentials, LoginData, MeData } from './types';

/** Identifica o dispositivo no token Sanctum (`device_name`). */
export const DEVICE_NAME = 'sigof-web';

export const authApi = {
  /** Sem Bearer: o login nunca usa o token salvo. */
  login: (credentials: LoginCredentials) =>
    api.post<LoginData>(
      '/auth/login',
      { ...credentials, device_name: DEVICE_NAME },
      { token: null },
    ),
  me: (token?: string, signal?: AbortSignal) => api.get<MeData>('/auth/me', { token, signal }),
  logout: () => api.post<null>('/auth/logout'),
};
