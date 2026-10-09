import { api } from '@/api';
import { APP_SLUG } from '@/config/brand';
import type { LoginCredentials, LoginData, MeData, ResetPasswordPayload } from './types';

/** Identifica o dispositivo no token Sanctum (`device_name`). */
export const DEVICE_NAME = `${APP_SLUG}-web`;

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
  /** Sempre 200 com mensagem genérica (sem enumeração); devolve a `message` do envelope. */
  forgotPassword: async (email: string) =>
    (await api.raw<null>('POST', '/auth/forgot-password', { body: { email }, token: null }))
      .message,
  /** Não faz login: devolve a `message` do envelope para o aviso na tela de login. */
  resetPassword: async (payload: ResetPasswordPayload) =>
    (await api.raw<null>('POST', '/auth/reset-password', { body: payload, token: null })).message,
};
