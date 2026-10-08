import { http, HttpResponse } from 'msw';
import { describe, expect, it, vi } from 'vitest';
import { server } from '@/test/server';
import { ApiClient } from './client';
import { ApiError, NETWORK_ERROR_MESSAGE } from './errors';

const BASE = 'http://sigof.test/api/v1';
const url = (path: string) => `${BASE}${path}`;

const envelope = (over: Record<string, unknown> = {}) => ({
  status: 'success',
  message: 'OK',
  errors: null,
  data: null,
  ...over,
});

function makeClient(over: Partial<ConstructorParameters<typeof ApiClient>[0]> = {}) {
  return new ApiClient({ baseUrl: BASE, ...over });
}

async function catchError(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (error) {
    expect(error).toBeInstanceOf(ApiError);
    return error as ApiError;
  }
  throw new Error('esperava ApiError');
}

describe('ApiClient', () => {
  it('sucesso: desembrulha o envelope e devolve data', async () => {
    server.use(
      http.get(url('/health'), () => HttpResponse.json(envelope({ data: { app: 'ok' } }))),
    );
    await expect(makeClient().get('/health')).resolves.toEqual({ app: 'ok' });
  });

  it('sucesso: getPage devolve data + meta', async () => {
    const meta = { current_page: 2, per_page: 15, total: 31, last_page: 3 };
    server.use(
      http.get(url('/equipments'), ({ request }) => {
        expect(new URL(request.url).searchParams.get('page')).toBe('2');
        return HttpResponse.json(envelope({ data: [{ id: 1 }], meta }));
      }),
    );
    const page = await makeClient().getPage('/equipments', { query: { page: 2, q: '' } });
    expect(page).toEqual({ data: [{ id: 1 }], meta });
  });

  it('envia corpo JSON e Bearer quando há token', async () => {
    let seen: { auth: string | null; body: unknown } | undefined;
    server.use(
      http.post(url('/things'), async ({ request }) => {
        seen = { auth: request.headers.get('Authorization'), body: await request.json() };
        return HttpResponse.json(envelope({ data: { id: 7 } }), { status: 201 });
      }),
    );
    const client = makeClient({ getToken: () => 'tok-123' });
    await expect(client.post('/things', { name: 'x' })).resolves.toEqual({ id: 7 });
    expect(seen).toEqual({ auth: 'Bearer tok-123', body: { name: 'x' } });
  });

  it('não envia Authorization sem token ou com token: null', async () => {
    const auths: (string | null)[] = [];
    server.use(
      http.get(url('/open'), ({ request }) => {
        auths.push(request.headers.get('Authorization'));
        return HttpResponse.json(envelope());
      }),
    );
    await makeClient().get('/open');
    await makeClient({ getToken: () => 'tok' }).get('/open', { token: null });
    expect(auths).toEqual([null, null]);
  });

  it('401: lança ApiError e notifica onUnauthorized', async () => {
    server.use(
      http.get(url('/auth/me'), () =>
        HttpResponse.json(envelope({ status: 'error', message: 'Não autenticado.' }), {
          status: 401,
        }),
      ),
    );
    const onUnauthorized = vi.fn();
    const error = await catchError(makeClient({ onUnauthorized }).get('/auth/me'));
    expect(error).toMatchObject({ status: 401, message: 'Não autenticado.', errors: null });
    expect(error.isUnauthorized).toBe(true);
    expect(onUnauthorized).toHaveBeenCalledOnce();
  });

  it('422: mapeia errors por campo', async () => {
    server.use(
      http.post(url('/equipments'), () =>
        HttpResponse.json(
          envelope({
            status: 'error',
            message: 'Dados inválidos.',
            errors: { plate: ['A placa já está em uso.'], code: ['O campo código é obrigatório.'] },
          }),
          { status: 422 },
        ),
      ),
    );
    const error = await catchError(makeClient().post('/equipments', {}));
    expect(error.status).toBe(422);
    expect(error.isValidation).toBe(true);
    expect(error.errors).toEqual({
      plate: ['A placa já está em uso.'],
      code: ['O campo código é obrigatório.'],
    });
    expect(error.fieldMessage('plate')).toBe('A placa já está em uso.');
    expect(error.fieldMessage('inexistente')).toBeUndefined();
  });

  it('409: preserva a mensagem de negócio do envelope', async () => {
    server.use(
      http.delete(url('/branches/1'), () =>
        HttpResponse.json(
          envelope({ status: 'error', message: 'Unidade possui equipamentos vinculados.' }),
          { status: 409 },
        ),
      ),
    );
    const error = await catchError(makeClient().delete('/branches/1'));
    expect(error).toMatchObject({
      status: 409,
      message: 'Unidade possui equipamentos vinculados.',
    });
    expect(error.isConflict).toBe(true);
  });

  it('500: erro do servidor com mensagem do envelope e X-Request-Id', async () => {
    server.use(
      http.get(url('/boom'), () =>
        HttpResponse.json(envelope({ status: 'error', message: 'Erro interno do servidor.' }), {
          status: 500,
          headers: { 'X-Request-Id': 'req-abc' },
        }),
      ),
    );
    const error = await catchError(makeClient().get('/boom'));
    expect(error).toMatchObject({
      status: 500,
      message: 'Erro interno do servidor.',
      requestId: 'req-abc',
    });
  });

  it('500 com corpo não-JSON (proxy): mensagem genérica', async () => {
    server.use(
      http.get(url('/html'), () => new HttpResponse('<html>Bad Gateway</html>', { status: 502 })),
    );
    const error = await catchError(makeClient().get('/html'));
    expect(error.status).toBe(502);
    expect(error.message).toMatch(/erro inesperado/i);
    expect(error.errors).toBeNull();
  });

  it('rede indisponível: ApiError status 0', async () => {
    server.use(http.get(url('/down'), () => HttpResponse.error()));
    const error = await catchError(makeClient().get('/down'));
    expect(error).toMatchObject({ status: 0, message: NETWORK_ERROR_MESSAGE });
    expect(error.isNetworkError).toBe(true);
  });

  it('abort: propaga AbortError sem virar ApiError', async () => {
    server.use(http.get(url('/slow'), () => HttpResponse.json(envelope())));
    const controller = new AbortController();
    controller.abort();
    await expect(makeClient().get('/slow', { signal: controller.signal })).rejects.toMatchObject({
      name: 'AbortError',
    });
  });
});
