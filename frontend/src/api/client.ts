import { ApiError, GENERIC_ERROR_MESSAGE, NETWORK_ERROR_MESSAGE } from './errors';
import type { FieldErrors } from './errors';

/** Envelope uniforme da API (spec D.1). */
export interface ApiEnvelope<T> {
  status: 'success' | 'error';
  message: string;
  errors: FieldErrors | null;
  data: T;
  meta?: PageMeta;
}

export interface PageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface Page<T> {
  data: T[];
  meta: PageMeta;
}

export type QueryValue = string | number | boolean | null | undefined;
export type QueryParams = Record<string, QueryValue | QueryValue[]>;

export interface RequestOptions {
  query?: QueryParams;
  body?: unknown;
  signal?: AbortSignal;
  headers?: Record<string, string>;
  /** Sobrescreve o token configurado (ex.: login). `null` força chamada sem Bearer. */
  token?: string | null;
}

export interface ApiClientConfig {
  baseUrl: string;
  /** Retorna o token Bearer atual, se houver. */
  getToken?: () => string | null | undefined;
  /** Chamado em qualquer 401 antes do erro ser lançado (limpar sessão / redirecionar). */
  onUnauthorized?: (error: ApiError) => void;
  fetch?: typeof fetch;
}

export const API_BASE_URL = '/api/v1';

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

function buildUrl(baseUrl: string, path: string, query?: QueryParams): string {
  const url = `${baseUrl.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`;
  if (!query) return url;

  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    for (const item of Array.isArray(value) ? value : [value]) {
      if (item !== undefined && item !== null && item !== '') params.append(key, String(item));
    }
  }
  const qs = params.toString();
  return qs ? `${url}?${qs}` : url;
}

function isEnvelope(value: unknown): value is ApiEnvelope<unknown> {
  return (
    typeof value === 'object' &&
    value !== null &&
    'status' in value &&
    ((value as { status: unknown }).status === 'success' ||
      (value as { status: unknown }).status === 'error')
  );
}

async function parseBody(response: Response): Promise<unknown> {
  const text = await response.text();
  if (!text) return null;
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return null; // corpo não-JSON (ex.: página de erro do proxy)
  }
}

export class ApiClient {
  private readonly config: ApiClientConfig;

  constructor(config: ApiClientConfig) {
    this.config = config;
  }

  /** Executa a requisição e devolve o envelope já validado. */
  async raw<T>(
    method: Method,
    path: string,
    options: RequestOptions = {},
  ): Promise<ApiEnvelope<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', ...options.headers };
    const token = options.token === undefined ? this.config.getToken?.() : options.token;
    if (token) headers.Authorization = `Bearer ${token}`;

    let body: BodyInit | undefined;
    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    }

    const doFetch = this.config.fetch ?? fetch;
    let response: Response;
    try {
      response = await doFetch(buildUrl(this.config.baseUrl, path, options.query), {
        method,
        headers,
        body,
        signal: options.signal,
      });
    } catch (cause) {
      if (options.signal?.aborted || (cause as { name?: string } | null)?.name === 'AbortError') {
        throw cause;
      }
      throw new ApiError({ status: 0, message: NETWORK_ERROR_MESSAGE, cause });
    }

    const requestId = response.headers.get('X-Request-Id');
    const payload = await parseBody(response);

    if (!response.ok || (isEnvelope(payload) && payload.status === 'error')) {
      const envelope = isEnvelope(payload) ? payload : null;
      const error = new ApiError({
        status: response.status,
        message: envelope?.message || GENERIC_ERROR_MESSAGE,
        errors: envelope?.errors ?? null,
        requestId,
      });
      if (response.status === 401) this.config.onUnauthorized?.(error);
      throw error;
    }

    if (!isEnvelope(payload)) {
      if (response.status === 204) {
        return { status: 'success', message: '', errors: null, data: null as T };
      }
      throw new ApiError({
        status: response.status,
        message: GENERIC_ERROR_MESSAGE,
        requestId,
      });
    }
    return payload as ApiEnvelope<T>;
  }

  async request<T>(method: Method, path: string, options?: RequestOptions): Promise<T> {
    return (await this.raw<T>(method, path, options)).data;
  }

  get<T>(path: string, options?: RequestOptions): Promise<T> {
    return this.request<T>('GET', path, options);
  }

  /** GET de lista paginada: devolve `data` + `meta`. */
  async getPage<T>(path: string, options?: RequestOptions): Promise<Page<T>> {
    const envelope = await this.raw<T[]>('GET', path, options);
    return {
      data: envelope.data,
      meta: envelope.meta ?? {
        current_page: 1,
        per_page: envelope.data.length,
        total: envelope.data.length,
        last_page: 1,
      },
    };
  }

  post<T>(path: string, body?: unknown, options?: RequestOptions): Promise<T> {
    return this.request<T>('POST', path, { ...options, body });
  }

  put<T>(path: string, body?: unknown, options?: RequestOptions): Promise<T> {
    return this.request<T>('PUT', path, { ...options, body });
  }

  patch<T>(path: string, body?: unknown, options?: RequestOptions): Promise<T> {
    return this.request<T>('PATCH', path, { ...options, body });
  }

  delete<T = null>(path: string, options?: RequestOptions): Promise<T> {
    return this.request<T>('DELETE', path, options);
  }
}

// --- Instância única da aplicação -------------------------------------------------------------

let tokenGetter: () => string | null | undefined = () => null;
let unauthorizedHandler: ((error: ApiError) => void) | undefined;

/** Plugado pelo AuthProvider (F1-23): fonte do token e reação a 401. */
export function configureApi(options: {
  getToken?: () => string | null | undefined;
  onUnauthorized?: (error: ApiError) => void;
}): void {
  if (options.getToken) tokenGetter = options.getToken;
  unauthorizedHandler = options.onUnauthorized;
}

export const api = new ApiClient({
  baseUrl: API_BASE_URL,
  getToken: () => tokenGetter(),
  onUnauthorized: (error) => unauthorizedHandler?.(error),
});
