import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { http } from 'msw';
import { API, mockAuthApi, ok, renderApp, storeSession, storedSession } from '@/test/auth';
import { server } from '@/test/server';
import { setViewport } from '@/test/viewport';

async function renderShell(path = '/ativos/equipamentos') {
  mockAuthApi('admin');
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1 });
  return view;
}

const banner = () => screen.getByRole('banner', { name: 'Barra superior' });
const avatarButton = () => screen.getByRole('button', { name: 'Menu do usuário: Ana Souza' });

/** Abre o menu. Com ele aberto (modal), o resto da página fica aria-hidden: guarde o trigger antes. */
async function openMenu() {
  const user = userEvent.setup();
  const trigger = avatarButton();
  await user.click(trigger);
  return { user, trigger, menu: await screen.findByRole('menu') };
}

describe('Topbar escura + menu do usuário (F1-36)', () => {
  it('topbar usa os tokens escuros e mostra filial, nome e perfil legíveis', async () => {
    await renderShell();
    expect(banner()).toHaveClass('bg-topbar', 'text-on-topbar');
    expect(within(banner()).getByText('Ana Souza')).toHaveClass('text-on-topbar');
    expect(within(banner()).getByText('PCM/Gestor/Admin')).toHaveClass('text-on-topbar-muted');
    expect(within(banner()).getByText('Todas as filiais').closest('p')).toHaveClass(
      'text-on-topbar-muted',
    );
  });

  it('avatar com as iniciais no lugar do botão Sair, com alvo ≥ 48px', async () => {
    await renderShell();
    const trigger = avatarButton();
    expect(within(trigger).getByTestId('avatar')).toHaveTextContent('AS');
    expect(trigger).toHaveClass('min-h-12', 'min-w-12', 'rounded-full');
    expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    expect(within(banner()).queryByRole('button', { name: 'Sair' })).toBeNull();
  });

  it('menu: cabeçalho com nome/perfil/filial e os 3 itens, com alvos ≥ 48px', async () => {
    await renderShell();
    const { menu, trigger } = await openMenu();
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(menu).toHaveTextContent('Ana Souza');
    expect(menu).toHaveTextContent('PCM/Gestor/Admin');
    expect(menu).toHaveTextContent('Todas as filiais');
    const items = within(menu).getAllByRole('menuitem');
    expect(items.map((item) => item.textContent)).toEqual([
      'Meu perfilEm breve',
      'ConfiguraçõesEm breve',
      'Sair',
    ]);
    for (const item of items) expect(item).toHaveClass('min-h-12');
  });

  it.each(['Meu perfil', 'Configurações'])(
    '%s: aria-disabled, selo "Em breve" e não navega (o menu continua aberto)',
    async (label) => {
      const { location } = await renderShell();
      const { user, menu } = await openMenu();
      const item = within(menu).getByRole('menuitem', { name: new RegExp(`^${label}`) });
      expect(item).toHaveAttribute('aria-disabled', 'true');
      expect(item).toHaveTextContent('Em breve');
      await user.click(item);
      expect(location()).toBe('/ativos/equipamentos');
      expect(screen.getByRole('menu')).toBeInTheDocument();
      expect(storedSession()).not.toBeNull();
    },
  );

  it('Sair faz o logout (API + storage) e vai para /login', async () => {
    let logoutCalls = 0;
    const { location } = await renderShell();
    server.use(
      http.post(API('/auth/logout'), () => {
        logoutCalls += 1;
        return ok(null);
      }),
    );
    const { user, menu } = await openMenu();
    await user.click(within(menu).getByRole('menuitem', { name: 'Sair' }));
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(location()).toBe('/login');
    expect(logoutCalls).toBe(1);
    expect(storedSession()).toBeNull();
  });

  it('teclado: Enter abre, setas percorrem (inclusive os desabilitados), Esc fecha e devolve o foco', async () => {
    await renderShell();
    const user = userEvent.setup();
    avatarButton().focus();
    await user.keyboard('{Enter}');
    const menu = await screen.findByRole('menu');
    const [perfil, config, sair] = within(menu).getAllByRole('menuitem');
    await waitFor(() => expect(perfil).toHaveFocus());
    await user.keyboard('{ArrowDown}');
    expect(config).toHaveFocus();
    await user.keyboard('{ArrowDown}');
    expect(sair).toHaveFocus();
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('menu')).toBeNull());
    expect(avatarButton()).toHaveFocus();
  });

  it('mobile (375): o avatar substitui o botão de sair e o menu funciona', async () => {
    setViewport(375);
    await renderShell();
    expect(screen.getByRole('button', { name: 'Abrir menu' })).toBeInTheDocument();
    expect(within(banner()).queryByText('PCM/Gestor/Admin')).toBeNull();
    const { menu } = await openMenu();
    expect(within(menu).getByRole('menuitem', { name: 'Sair' })).toBeInTheDocument();
  });

  it('sem violações axe (topbar e menu aberto)', async () => {
    const { container } = await renderShell();
    expect(await axe(container)).toHaveNoViolations();
    // O menu vai para um portal no body (como Modal/Drawer): axe no próprio menu.
    const { menu } = await openMenu();
    expect(await axe(menu)).toHaveNoViolations();
  });
});
