import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { http } from 'msw';
import { API, INVALID_DATA, fail, mockAuthApi, ok, renderApp, storeSession } from '@/test/auth';
import { server } from '@/test/server';
import { INVALID_LINK_MESSAGE } from './ResetPasswordPage';
import { readResetLink } from './resetLink';

const FORGOT_OK =
  'Se o e-mail estiver cadastrado e ativo, você receberá um link para redefinir a senha.';
const RESET_OK = 'Senha redefinida. Entre com a nova senha.';
const TOKEN = 'a1b2c3d4e5f6';
/** Link do e-mail (v1.7a): token e e-mail no fragmento, não na query. */
const RESET_PATH = `/redefinir-senha#token=${TOKEN}&email=ana%40example.com`;
const STRONG = 'NovaSenha123';

/** Contrato v1.7 (F1-34): forgot sempre 200 genérico; e-mail vazio/malformado → errors.email. */
function mockForgot() {
  const bodies: unknown[] = [];
  server.use(
    http.post(API('/auth/forgot-password'), async ({ request }) => {
      const body = (await request.json()) as { email?: string };
      bodies.push(body);
      if (!body.email) {
        return fail(422, INVALID_DATA, { email: ['O campo e-mail é obrigatório.'] });
      }
      if (!body.email.includes('@')) {
        return fail(422, INVALID_DATA, { email: ['O campo e-mail deve ser um e-mail válido.'] });
      }
      return ok(null, FORGOT_OK);
    }),
  );
  return bodies;
}

/**
 * Reset (v1.7): política de senha (antes do token) e confirmação em errors.password; token
 * inválido/expirado/usado → errors.token genérico; sucesso sem login automático.
 */
function mockReset({ validToken = TOKEN } = {}) {
  const bodies: Record<string, unknown>[] = [];
  server.use(
    http.post(API('/auth/reset-password'), async ({ request }) => {
      const body = (await request.json()) as Record<string, string>;
      bodies.push(body);
      const password = body.password ?? '';
      if (password.length < 10 || !/[A-Z]/.test(password) || !/\d/.test(password)) {
        return fail(422, INVALID_DATA, {
          password: ['A senha deve ter ao menos 10 caracteres, com maiúscula, minúscula e número.'],
        });
      }
      if (password !== body.password_confirmation) {
        return fail(422, INVALID_DATA, {
          password: ['A confirmação do campo senha não confere.'],
        });
      }
      if (body.token !== validToken || body.email !== 'ana@example.com') {
        return fail(422, INVALID_DATA, { token: [INVALID_LINK_MESSAGE] });
      }
      return ok(null, RESET_OK);
    }),
  );
  return bodies;
}

describe('Esqueci a senha (/esqueci-senha)', () => {
  it('login tem o link "Esqueceu sua senha?" para a tela pública', async () => {
    mockAuthApi();
    const user = userEvent.setup();
    const { location } = renderApp('/login');
    await user.click(await screen.findByRole('link', { name: 'Esqueceu sua senha?' }));
    expect(location()).toBe('/esqueci-senha');
    expect(await screen.findByRole('heading', { name: 'Esqueceu sua senha?' })).toBeInTheDocument();
  });

  it('envia o e-mail e mostra a mensagem genérica do envelope + Voltar para o login', async () => {
    mockAuthApi();
    const bodies = mockForgot();
    const user = userEvent.setup();
    const { location } = renderApp('/esqueci-senha');
    await user.type(await screen.findByLabelText('E-mail'), ' ana@example.com ');
    await user.click(screen.getByRole('button', { name: 'Enviar' }));

    expect(await screen.findByRole('status')).toHaveTextContent(FORGOT_OK);
    expect(bodies).toEqual([{ email: 'ana@example.com' }]);
    expect(screen.queryByRole('button', { name: 'Enviar' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('link', { name: 'Voltar para o login' }));
    expect(location()).toBe('/login');
  });

  it('sem bloqueio no cliente: vazio vai ao backend e o 422 aparece no campo', async () => {
    mockAuthApi();
    const bodies = mockForgot();
    const user = userEvent.setup();
    renderApp('/esqueci-senha');
    await user.click(await screen.findByRole('button', { name: 'Enviar' }));
    const email = screen.getByLabelText('E-mail');
    await waitFor(() => expect(email).toHaveAttribute('aria-invalid', 'true'));
    expect(email).toHaveAccessibleDescription('O campo e-mail é obrigatório.');
    expect(email).toHaveAttribute('aria-required', 'true');
    expect(bodies).toHaveLength(1);

    await user.type(email, 'ana');
    await user.click(screen.getByRole('button', { name: 'Enviar' }));
    await waitFor(() =>
      expect(email).toHaveAccessibleDescription('O campo e-mail deve ser um e-mail válido.'),
    );
  });

  it('429: mensagem com a contagem do Retry-After e envio bloqueado', async () => {
    mockAuthApi();
    let calls = 0;
    server.use(
      http.post(API('/auth/forgot-password'), () => {
        calls += 1;
        return fail(429, 'Muitas requisições. Tente novamente em instantes.', null, {
          'Retry-After': '45',
        });
      }),
    );
    const user = userEvent.setup();
    renderApp('/esqueci-senha');
    await user.type(await screen.findByLabelText('E-mail'), 'ana@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar' }));
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Muitas requisições');
    expect(alert).toHaveTextContent('Tente novamente em 45 segundos.');
    expect(screen.getByRole('button', { name: 'Enviar' })).toBeDisabled();
    await user.type(screen.getByLabelText('E-mail'), '{Enter}');
    expect(calls).toBe(1);
  });
});

describe('Redefinir senha (/redefinir-senha)', () => {
  it('readResetLink: lê do fragmento com URLSearchParams (sem o #)', () => {
    expect(readResetLink('#token=abc123&email=ana%40example.com')).toEqual({
      token: 'abc123',
      email: 'ana@example.com',
    });
    expect(readResetLink('token=abc123&email=ana%2Bfrota%40example.com').email).toBe(
      'ana+frota@example.com',
    );
    // `+` cru vira espaço: o backend precisa codificar o e-mail no link (rawurlencode).
    expect(readResetLink('#email=ana+frota@example.com').email).toBe('ana frota@example.com');
    expect(readResetLink('')).toEqual({ token: '', email: '' });
  });

  it('link antigo com query (?token=) não é lido: aviso de link inválido e URL limpa', async () => {
    mockAuthApi();
    const bodies = mockReset();
    const { location } = renderApp(`/redefinir-senha?token=${TOKEN}&email=ana%40example.com`);
    expect(await screen.findByRole('alert')).toHaveTextContent(INVALID_LINK_MESSAGE);
    await waitFor(() => expect(location()).toBe('/redefinir-senha'));
    expect(bodies).toHaveLength(0);
  });

  it('lê token e e-mail do fragmento e limpa o fragmento ao abrir', async () => {
    mockAuthApi();
    mockReset();
    const { location } = renderApp(RESET_PATH);
    expect(await screen.findByRole('heading', { name: 'Redefinir senha' })).toBeInTheDocument();
    await waitFor(() => expect(location()).toBe('/redefinir-senha'));
    expect(screen.getByText(/ana@example\.com/)).toBeInTheDocument();
  });

  it('sucesso envia token/e-mail guardados e vai ao login com o aviso (sem login automático)', async () => {
    mockAuthApi();
    const bodies = mockReset();
    const user = userEvent.setup();
    const { location } = renderApp(RESET_PATH);
    await user.type(await screen.findByLabelText('Nova senha'), STRONG);
    await user.type(screen.getByLabelText('Confirmar nova senha'), STRONG);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => expect(location()).toBe('/login'));
    expect(await screen.findByRole('status')).toHaveTextContent(RESET_OK);
    expect(bodies).toEqual([
      {
        email: 'ana@example.com',
        token: TOKEN,
        password: STRONG,
        password_confirmation: STRONG,
      },
    ]);
    expect(localStorage.length).toBe(0);
  });

  it('422 da política e da confirmação aparecem no campo de senha', async () => {
    mockAuthApi();
    mockReset();
    const user = userEvent.setup();
    renderApp(RESET_PATH);
    const password = await screen.findByLabelText('Nova senha');
    expect(password).toHaveAttribute('autocomplete', 'new-password');
    await user.type(password, 'fraca');
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));
    await waitFor(() => expect(password).toHaveAttribute('aria-invalid', 'true'));
    expect(password).toHaveAccessibleDescription(/ao menos 10 caracteres/);

    await user.clear(password);
    await user.type(password, STRONG);
    await user.type(screen.getByLabelText('Confirmar nova senha'), 'OutraSenha123');
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));
    await waitFor(() =>
      expect(password).toHaveAccessibleDescription(/A confirmação do campo senha não confere\./),
    );
    expect(screen.getByRole('heading', { name: 'Redefinir senha' })).toBeInTheDocument();
  });

  it('token inválido/expirado: mensagem genérica e link para pedir outro', async () => {
    mockAuthApi();
    mockReset({ validToken: 'outro-token' });
    const user = userEvent.setup();
    const { location } = renderApp(RESET_PATH);
    await user.type(await screen.findByLabelText('Nova senha'), STRONG);
    await user.type(screen.getByLabelText('Confirmar nova senha'), STRONG);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(INVALID_LINK_MESSAGE);
    expect(screen.queryByLabelText('Nova senha')).not.toBeInTheDocument();
    await user.click(screen.getByRole('link', { name: 'Pedir novo link' }));
    expect(location()).toBe('/esqueci-senha');
  });

  it.each([
    ['sem token', '/redefinir-senha#email=ana%40example.com'],
    ['sem e-mail', `/redefinir-senha#token=${TOKEN}`],
    ['fragmento vazio', '/redefinir-senha#'],
    ['sem nada (ex.: recarregou depois de limpar a URL)', '/redefinir-senha'],
  ])('link incompleto (%s) já abre no aviso de link inválido', async (_case, path) => {
    mockAuthApi();
    const bodies = mockReset();
    renderApp(path);
    expect(await screen.findByRole('alert')).toHaveTextContent(INVALID_LINK_MESSAGE);
    expect(screen.getByRole('link', { name: 'Pedir novo link' })).toBeInTheDocument();
    expect(bodies).toHaveLength(0);
  });

  it('429: contagem do Retry-After', async () => {
    mockAuthApi();
    server.use(
      http.post(API('/auth/reset-password'), () =>
        fail(429, 'Muitas requisições.', null, { 'Retry-After': '1' }),
      ),
    );
    const user = userEvent.setup();
    renderApp(RESET_PATH);
    await user.type(await screen.findByLabelText('Nova senha'), STRONG);
    await user.type(screen.getByLabelText('Confirmar nova senha'), STRONG);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Tente novamente em 1 segundo.');
  });
});

describe('Telas públicas', () => {
  it.each(['/esqueci-senha', RESET_PATH])('%s com sessão redireciona para o app', async (path) => {
    mockAuthApi('admin');
    storeSession();
    const { location } = renderApp(path);
    await screen.findByRole('heading', { name: 'Frotas & Equipamentos' });
    expect(location()).toBe('/ativos/equipamentos');
  });

  it.each([
    ['/login', 'Entrar'],
    ['/esqueci-senha', 'Esqueceu sua senha?'],
    [RESET_PATH, 'Redefinir senha'],
  ])('%s: sem asterisco, alvos ≥ 48px e sem violações axe', async (path, title) => {
    mockAuthApi();
    const { container } = renderApp(path);
    await screen.findByRole('heading', { name: title });
    expect(container).not.toHaveTextContent('*');
    for (const control of [
      ...screen.getAllByRole('button'),
      ...screen.getAllByRole('link'),
      ...container.querySelectorAll('input'),
    ]) {
      expect(control).toHaveClass('min-h-12', 'min-w-12');
    }
    for (const input of container.querySelectorAll('input')) {
      expect(input).toHaveAttribute('aria-required', 'true');
    }
    expect(await axe(container)).toHaveNoViolations();
  });

  it('login mostra o aviso vindo da redefinição e some ao errar o login', async () => {
    mockAuthApi();
    mockReset();
    const user = userEvent.setup();
    renderApp(RESET_PATH);
    await user.type(await screen.findByLabelText('Nova senha'), STRONG);
    await user.type(screen.getByLabelText('Confirmar nova senha'), STRONG);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));
    const notice = await screen.findByRole('status');
    expect(within(notice).getByText(RESET_OK)).toBeInTheDocument();

    await user.type(screen.getByLabelText('Usuário'), 'anasouza');
    await user.type(screen.getByLabelText(/^Senha/), 'errada');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));
    await screen.findByRole('alert');
    expect(screen.queryByText(RESET_OK)).not.toBeInTheDocument();
  });
});
