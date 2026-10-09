import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { http } from 'msw';
import { api } from '@/api';
import {
  API,
  fail,
  futureIso,
  mockAuthApi,
  ok,
  renderApp,
  storeSession,
  storedSession,
} from '@/test/auth';
import { server } from '@/test/server';
import type { Role } from './types';

async function fillAndSubmit(email: string, password: string) {
  const user = userEvent.setup();
  await user.type(await screen.findByLabelText(/E-mail/), email);
  await user.type(screen.getByLabelText(/^Senha/), password);
  await user.click(screen.getByRole('button', { name: 'Entrar' }));
  return user;
}

describe('Login', () => {
  it('foco automático no e-mail', async () => {
    mockAuthApi();
    renderApp('/login');
    expect(await screen.findByLabelText(/E-mail/)).toHaveFocus();
  });

  it('login ok → /ativos/equipamentos, token salvo e device_name enviado', async () => {
    mockAuthApi('admin');
    let body: Record<string, unknown> | undefined;
    server.events.on('request:start', async ({ request }) => {
      if (request.url.endsWith('/auth/login')) body = await request.clone().json();
    });
    const { location } = renderApp('/login');
    await fillAndSubmit('ana@example.com', 'senha-correta');
    expect(
      await screen.findByRole('heading', { name: 'Frotas & Equipamentos' }),
    ).toBeInTheDocument();
    expect(location()).toBe('/ativos/equipamentos');
    expect(storedSession()).toMatchObject({ token: 'tok-novo' });
    expect(body).toMatchObject({ email: 'ana@example.com', device_name: 'sigof-web' });
    expect(screen.getByText('Ana Souza')).toBeInTheDocument();
    expect(screen.getByText('PCM/Gestor/Admin')).toBeInTheDocument();
    server.events.removeAllListeners();
  });

  it('respeita next relativo', async () => {
    mockAuthApi('admin');
    const { location } = renderApp('/login?next=%2Fparametros');
    await fillAndSubmit('ana@example.com', 'senha-correta');
    await screen.findByRole('heading', { name: 'Painel de Parâmetros' });
    expect(location()).toBe('/parametros');
  });

  it.each(['//evil.example', '/\\evil.example', 'https://evil.example'])(
    'ignora next inseguro %s',
    async (next) => {
      mockAuthApi('admin');
      const { location } = renderApp(`/login?next=${encodeURIComponent(next)}`);
      await fillAndSubmit('ana@example.com', 'senha-correta');
      await screen.findByRole('heading', { name: 'Frotas & Equipamentos' });
      expect(location()).toBe('/ativos/equipamentos');
    },
  );

  it('credenciais inválidas mostram a mensagem do envelope e não salvam sessão', async () => {
    mockAuthApi();
    const { location } = renderApp('/login');
    await fillAndSubmit('ana@example.com', 'errada');
    expect(await screen.findByRole('alert')).toHaveTextContent('Credenciais inválidas.');
    expect(location()).toBe('/login');
    expect(storedSession()).toBeNull();
  });

  it('usuário inativo (403) mostra a mensagem do envelope', async () => {
    server.use(
      http.post(API('/auth/login'), () => fail(403, 'Usuário inativo. Procure o administrador.')),
    );
    renderApp('/login');
    await fillAndSubmit('ana@example.com', 'senha-correta');
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Usuário inativo. Procure o administrador.',
    );
  });

  it('429 bloqueia o envio pelo tempo do Retry-After', async () => {
    let calls = 0;
    server.use(
      http.post(API('/auth/login'), () => {
        calls += 1;
        return fail(429, 'Muitas requisições. Tente novamente em instantes.', null, {
          'Retry-After': '30',
        });
      }),
    );
    renderApp('/login');
    const user = await fillAndSubmit('ana@example.com', 'qualquer');
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Muitas requisições');
    expect(alert).toHaveTextContent('Tente novamente em 30 segundos.');
    const submit = screen.getByRole('button', { name: 'Entrar' });
    expect(submit).toBeDisabled();
    await user.type(screen.getByLabelText(/^Senha/), '{Enter}');
    expect(calls).toBe(1);
  });

  it('429: o bloqueio termina ao fim da contagem', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    server.use(
      http.post(API('/auth/login'), () =>
        fail(429, 'Muitas requisições.', null, { 'Retry-After': '2' }),
      ),
    );
    renderApp('/login');
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    await user.type(await screen.findByLabelText(/E-mail/), 'a@x.com');
    await user.type(screen.getByLabelText(/^Senha/), 'x');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));
    expect(await screen.findByText('Tente novamente em 2 segundos.')).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1000);
    });
    expect(screen.getByText('Tente novamente em 1 segundo.')).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1000);
    });
    expect(screen.getByRole('button', { name: 'Entrar' })).toBeEnabled();
    vi.useRealTimers();
  });

  it('validação local: campos vazios não chamam a API', async () => {
    let calls = 0;
    server.use(
      http.post(API('/auth/login'), () => {
        calls += 1;
        return ok(null);
      }),
    );
    renderApp('/login');
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Entrar' }));
    expect(screen.getByLabelText(/E-mail/)).toHaveAccessibleDescription('Informe o e-mail.');
    expect(screen.getByLabelText(/^Senha/)).toHaveAccessibleDescription('Informe a senha.');
    expect(calls).toBe(0);
  });

  it('mostrar/ocultar senha', async () => {
    mockAuthApi();
    renderApp('/login');
    const user = userEvent.setup();
    const password = await screen.findByLabelText(/^Senha/);
    expect(password).toHaveAttribute('type', 'password');
    await user.click(screen.getByRole('button', { name: 'Mostrar senha' }));
    expect(password).toHaveAttribute('type', 'text');
    expect(screen.getByRole('button', { name: 'Ocultar senha' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    await user.click(screen.getByRole('button', { name: 'Ocultar senha' }));
    expect(password).toHaveAttribute('type', 'password');
  });

  it('rede indisponível mostra mensagem do cliente', async () => {
    server.use(http.post(API('/auth/login'), () => Response.error()));
    renderApp('/login');
    await fillAndSubmit('ana@example.com', 'senha-correta');
    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível conectar');
  });

  it('já logado: /login redireciona para next', async () => {
    mockAuthApi('admin');
    storeSession();
    const { location } = renderApp('/login?next=%2Fparametros');
    await screen.findByRole('heading', { name: 'Painel de Parâmetros' });
    expect(location()).toBe('/parametros');
  });

  it('não tem violações de acessibilidade (inicial e com erro)', async () => {
    mockAuthApi();
    const { container } = renderApp('/login');
    await screen.findByLabelText(/E-mail/);
    expect(await axe(container)).toHaveNoViolations();
    await fillAndSubmit('ana@example.com', 'errada');
    await screen.findByRole('alert');
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('Guardas de rota', () => {
  it('rota protegida sem sessão → /login?next=', async () => {
    mockAuthApi();
    const { location } = renderApp('/parametros?scope=branch');
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(location()).toBe('/login?next=%2Fparametros%3Fscope%3Dbranch');
  });

  it('/ sem sessão → /login (sem next)', async () => {
    mockAuthApi();
    const { location } = renderApp('/');
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(location()).toBe('/login');
  });

  it('token salvo expirado não chama /auth/me e vai ao login', async () => {
    let meCalls = 0;
    server.use(
      http.get(API('/auth/me'), () => {
        meCalls += 1;
        return fail(401, 'x');
      }),
    );
    storeSession('tok-velho', new Date(Date.now() - 1000).toISOString());
    renderApp('/ativos/equipamentos');
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(meCalls).toBe(0);
    expect(storedSession()).toBeNull();
  });

  it('reload com sessão salva usa o token salvo', async () => {
    let auth: string | null = null;
    mockAuthApi('leader');
    server.events.on('request:start', ({ request }) => {
      if (request.url.endsWith('/auth/me')) auth = request.headers.get('Authorization');
    });
    storeSession('tok-salvo');
    renderApp('/ativos/equipamentos');
    await screen.findByRole('heading', { name: 'Frotas & Equipamentos' });
    expect(auth).toBe('Bearer tok-salvo');
    server.events.removeAllListeners();
  });

  it('erro de rede/servidor ao validar sessão: tela de erro com Tentar novamente', async () => {
    let fails = true;
    mockAuthApi('admin');
    server.use(
      http.get(API('/auth/me'), () =>
        fails
          ? fail(500, 'Erro interno do servidor.')
          : ok({
              user: { id: 1, name: 'Ana Souza', email: 'a@x', role: 'admin', branch: null },
              employee: null,
              permissions: ['equipments.view'],
            }),
      ),
    );
    storeSession();
    renderApp('/ativos/equipamentos');
    expect(await screen.findByRole('alert')).toHaveTextContent('Erro interno do servidor.');
    fails = false;
    await userEvent.setup().click(screen.getByRole('button', { name: 'Tentar novamente' }));
    expect(
      await screen.findByRole('heading', { name: 'Frotas & Equipamentos' }),
    ).toBeInTheDocument();
  });

  it('usuário sem permissão → /403', async () => {
    mockAuthApi('operator');
    storeSession();
    const { location } = renderApp('/parametros');
    expect(await screen.findByRole('heading', { name: 'Acesso negado' })).toBeInTheDocument();
    expect(location()).toBe('/403');
  });

  it('401 em qualquer chamada limpa a sessão e vai a /login?next=', async () => {
    mockAuthApi('admin');
    server.use(http.get(API('/equipments'), () => fail(401, 'Não autenticado.')));
    storeSession();
    const { location } = renderApp('/parametros');
    await screen.findByRole('heading', { name: 'Painel de Parâmetros' });
    await act(async () => {
      await api.get('/equipments').catch(() => undefined);
    });
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(location()).toBe('/login?next=%2Fparametros');
    expect(storedSession()).toBeNull();
    expect(screen.getByRole('status')).toHaveTextContent('Sua sessão não é mais válida.');
  });

  it('chamadas autenticadas levam o Bearer da sessão', async () => {
    let auth: string | null = null;
    mockAuthApi('admin');
    server.use(
      http.get(API('/equipments'), ({ request }) => {
        auth = request.headers.get('Authorization');
        return ok([]);
      }),
    );
    storeSession('tok-salvo');
    renderApp('/ativos/equipamentos');
    await screen.findByRole('heading', { name: 'Frotas & Equipamentos' });
    await act(async () => {
      await api.get('/equipments');
    });
    expect(auth).toBe('Bearer tok-salvo');
  });

  it('logout chama a API, limpa o storage e vai a /login', async () => {
    let logoutAuth: string | null = null;
    mockAuthApi('admin');
    server.use(
      http.post(API('/auth/logout'), ({ request }) => {
        logoutAuth = request.headers.get('Authorization');
        return ok(null);
      }),
    );
    storeSession('tok-salvo');
    const { location } = renderApp('/ativos/equipamentos');
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Sair' }));
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(logoutAuth).toBe('Bearer tok-salvo');
    expect(storedSession()).toBeNull();
    expect(location()).toBe('/login');
  });

  it('logout com falha na API encerra a sessão local mesmo assim', async () => {
    mockAuthApi('admin');
    server.use(http.post(API('/auth/logout'), () => fail(500, 'Erro.')));
    storeSession();
    renderApp('/ativos/equipamentos');
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Sair' }));
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(storedSession()).toBeNull();
  });

  it('sessão expira no expires_at e volta ao login com aviso', async () => {
    mockAuthApi('admin');
    storeSession('tok-salvo', futureIso(300));
    const { location } = renderApp('/ativos/equipamentos');
    await screen.findByRole('heading', { name: 'Frotas & Equipamentos' });
    await screen.findByRole('heading', { name: 'Entrar' }, { timeout: 2000 });
    expect(screen.getByRole('status')).toHaveTextContent('Sua sessão expirou.');
    expect(location()).toBe('/login?next=%2Fativos%2Fequipamentos');
    expect(storedSession()).toBeNull();
  });

  it('logout em outra aba encerra a sessão aqui', async () => {
    mockAuthApi('admin');
    storeSession();
    renderApp('/ativos/equipamentos');
    await screen.findByRole('heading', { name: 'Frotas & Equipamentos' });
    act(() => {
      localStorage.clear();
      window.dispatchEvent(new StorageEvent('storage', { key: 'sigof.session', newValue: null }));
    });
    await screen.findByRole('heading', { name: 'Entrar' });
  });

  it('404 para rota inexistente', async () => {
    mockAuthApi();
    const { container } = renderApp('/nao-existe');
    expect(
      await screen.findByRole('heading', { name: 'Página não encontrada' }),
    ).toBeInTheDocument();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('403 sem violações de acessibilidade', async () => {
    mockAuthApi('operator');
    storeSession();
    const { container } = renderApp('/parametros');
    await screen.findByRole('heading', { name: 'Acesso negado' });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('Acesso por perfil (matriz E)', () => {
  const cases: [Role, { equipamentos: boolean; parametros: boolean }][] = [
    ['operator', { equipamentos: true, parametros: false }],
    ['mechanic', { equipamentos: true, parametros: false }],
    ['leader', { equipamentos: true, parametros: true }],
    ['admin', { equipamentos: true, parametros: true }],
  ];

  it.each(cases)('%s', async (role, expected) => {
    mockAuthApi(role);
    storeSession();
    const first = renderApp('/ativos/equipamentos');
    await screen.findByRole('heading', {
      name: expected.equipamentos ? 'Frotas & Equipamentos' : 'Acesso negado',
    });
    first.unmount();

    const second = renderApp('/parametros');
    await screen.findByRole('heading', {
      name: expected.parametros ? 'Painel de Parâmetros' : 'Acesso negado',
    });
    expect(second.location()).toBe(expected.parametros ? '/parametros' : '/403');
  });

  it('/ redireciona para Equipamentos após autenticar', async () => {
    mockAuthApi('mechanic');
    storeSession();
    const { location } = renderApp('/');
    await screen.findByRole('heading', { name: 'Frotas & Equipamentos' });
    await waitFor(() => expect(location()).toBe('/ativos/equipamentos'));
  });
});
