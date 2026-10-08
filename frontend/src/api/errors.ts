/** Erros por campo do envelope (422): `{ campo: ["mensagem", ...] }`. */
export type FieldErrors = Record<string, string[]>;

export interface ApiErrorInit {
  status: number;
  message: string;
  errors?: FieldErrors | null;
  requestId?: string | null;
  cause?: unknown;
}

/**
 * Erro único lançado pelo cliente HTTP.
 * `status === 0` indica falha de rede (sem resposta do servidor).
 */
export class ApiError extends Error {
  readonly status: number;
  readonly errors: FieldErrors | null;
  readonly requestId: string | null;

  constructor({ status, message, errors = null, requestId = null, cause }: ApiErrorInit) {
    super(message, { cause });
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.requestId = requestId;
  }

  get isNetworkError(): boolean {
    return this.status === 0;
  }

  get isUnauthorized(): boolean {
    return this.status === 401;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  get isConflict(): boolean {
    return this.status === 409;
  }

  /** Primeira mensagem de um campo (útil para setError do react-hook-form). */
  fieldMessage(field: string): string | undefined {
    return this.errors?.[field]?.[0];
  }
}

export const NETWORK_ERROR_MESSAGE =
  'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.';
export const GENERIC_ERROR_MESSAGE = 'Ocorreu um erro inesperado. Tente novamente.';
