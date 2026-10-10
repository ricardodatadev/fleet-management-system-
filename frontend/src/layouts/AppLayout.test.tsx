import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { setViewport } from '@/test/viewport';
import type { Role } from '@/features/auth';

async function renderShell(role: Role = 'admin', path = '/ativos/equipamentos') {
  mockAuthApi(role);
  storeSession();
  const view = renderApp(path);
  await screen.findByRole('heading', { level: 1 });
  return view;
}

const mainNav = () => screen.getByRole('navigation', { name: 'Navegação principal' });
const groupNames = () =>
  within(mainNav())
    .getAllByRole('group')
    .map((g) => g.getAttribute('aria-labelledby'))
    .map((id) => document.getElementById(id ?? '')?.textContent);

describe('AppLayout — desktop (≥1024)', () => {
  it('sidebar com os grupos na ordem e topbar com filial, usuário, perfil e Sair', async () => {
    await renderShell('admin');
    expect(groupNames()).toEqual([
      'Painéis & Indicadores',
      'Gestão de Ativos & Manutenção',
      'Planejamento & Execução de Manutenção',
      'Gestão de Pneus',
      'Suprimentos & Almoxarifado',
      'Financeiro & Custos',
      'Telemetria & Rastreamento',
      'Cadastros Gerais',
      'Administração',
    ]);
    const banner = screen.getByRole('banner', { name: 'Barra superior' });
    expect(banner).toHaveTextContent('Todas as filiais');
    expect(banner).toHaveTextContent('Ana Souza');
    expect(banner).toHaveTextContent('PCM/Gestor/Admin');
    expect(
      within(banner).getByRole('button', { name: 'Menu do usuário: Ana Souza' }),
    ).toBeInTheDocument();
    expect(within(banner).queryByRole('button', { name: 'Sair' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Abrir menu' })).not.toBeInTheDocument();
  });

  it('item ativo marca aria-current e navega entre itens habilitados', async () => {
    const user = userEvent.setup();
    const { location } = await renderShell('admin');
    const frotas = within(mainNav()).getByRole('link', { name: 'Frotas & Equipamentos' });
    expect(frotas).toHaveAttribute('aria-current', 'page');
    await user.click(within(mainNav()).getByRole('link', { name: 'Painel de Parâmetros' }));
    expect(await screen.findByRole('heading', { name: 'Painel de Parâmetros' })).toBeVisible();
    expect(location()).toBe('/parametros');
  });

  it('itens desabilitados: aria-disabled, "Em breve", tooltip, focáveis e sem navegação', async () => {
    const user = userEvent.setup();
    const { location } = await renderShell('admin');
    const item = within(mainNav()).getByRole('button', { name: /Ordens de Serviço/ });
    expect(item).toHaveAttribute('aria-disabled', 'true');
    expect(item).toHaveTextContent('Em breve');
    act(() => item.focus());
    expect(item).toHaveFocus();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Disponível em fase futura');
    await user.keyboard('{Enter}');
    await user.click(item);
    expect(location()).toBe('/ativos/equipamentos');
  });

  it('admin vê o grupo ADMINISTRAÇÃO; mecânico não', async () => {
    const first = await renderShell('admin');
    expect(within(mainNav()).getByRole('group', { name: 'Administração' })).toBeInTheDocument();
    first.unmount();
    await renderShell('mechanic');
    expect(within(mainNav()).queryByRole('group', { name: 'Administração' })).toBeNull();
    expect(screen.getByRole('banner', { name: 'Barra superior' })).toHaveTextContent('Matriz');
    expect(screen.getByRole('banner', { name: 'Barra superior' })).toHaveTextContent('Mecânico');
  });

  it('navegação por teclado: Tab percorre topbar e sidebar até o item habilitado', async () => {
    const user = userEvent.setup();
    const { location } = await renderShell('leader', '/ativos/equipamentos');
    const target = within(mainNav()).getByRole('link', { name: 'Painel de Parâmetros' });
    for (let i = 0; i < 60 && document.activeElement !== target; i += 1) await user.tab();
    expect(target).toHaveFocus();
    await user.keyboard('{Enter}');
    await waitFor(() => expect(location()).toBe('/parametros'));
  });

  it('skip link leva o foco ao conteúdo', async () => {
    const user = userEvent.setup();
    await renderShell('admin');
    await user.tab();
    const skip = screen.getByRole('link', { name: 'Ir para o conteúdo' });
    expect(skip).toHaveFocus();
    await user.keyboard('{Enter}');
    expect(screen.getByRole('main')).toHaveFocus();
  });

  it('fora do modo ícones não há divisores (os títulos separam os grupos)', async () => {
    await renderShell('admin');
    expect(within(mainNav()).queryAllByTestId('nav-separator')).toHaveLength(0);
  });

  it('assistente virtual: botão flutuante desabilitado', async () => {
    await renderShell('admin');
    expect(
      screen.getByRole('button', { name: 'Assistente Virtual IA (em breve)' }),
    ).toHaveAttribute('aria-disabled', 'true');
  });

  it('todos os alvos do shell têm área mínima de 48px', async () => {
    await renderShell('admin');
    const controls = [...screen.getAllByRole('link'), ...screen.getAllByRole('button')];
    expect(controls.length).toBeGreaterThan(30);
    controls.forEach((control) => expect(control).toHaveClass('min-h-12', 'min-w-12'));
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = await renderShell('admin');
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('AppLayout — tablet (768–1023)', () => {
  beforeEach(() => setViewport(800));

  it('sidebar recolhida em ícones: rótulos só para leitor de tela, tooltip com o nome', async () => {
    await renderShell('admin');
    expect(screen.getByTestId('sidebar-panel')).toHaveClass('w-20');
    const link = within(mainNav()).getByRole('link', { name: 'Frotas & Equipamentos' });
    expect(within(link).getByText('Frotas & Equipamentos')).toHaveClass('sr-only');
    const group = within(mainNav()).getByRole('group', { name: 'Gestão de Pneus' });
    expect(within(group).getByText('Gestão de Pneus')).toHaveClass('sr-only');
    act(() => link.focus());
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Frotas & Equipamentos');
    // Regressão: o Slot do Tooltip não pode quebrar o estilo do item ativo.
    expect(link).toHaveAttribute('aria-current', 'page');
    expect(link).toHaveClass('bg-brand', 'justify-center');
    const disabled = within(mainNav()).getByRole('button', { name: /TCO/ });
    expect(disabled).toHaveAccessibleName(/Em breve/);
  });

  it('ícone de item desabilitado é esmaecido; o habilitado não', async () => {
    await renderShell('admin', '/parametros');
    const disabled = within(mainNav()).getByRole('button', { name: /Ordens de Serviço/ });
    const enabled = within(mainNav()).getByRole('link', { name: 'Frotas & Equipamentos' });
    expect(within(disabled).getByTestId('nav-icon')).toHaveClass('opacity-50');
    expect(disabled).toHaveClass('text-text-muted');
    expect(within(enabled).getByTestId('nav-icon')).not.toHaveClass('opacity-50');
    expect(enabled).toHaveClass('text-text');
    expect(enabled).not.toHaveClass('opacity-70');
    // Continua focável, com tooltip e aria-disabled.
    act(() => disabled.focus());
    expect(disabled).toHaveFocus();
    expect(disabled).toHaveAttribute('aria-disabled', 'true');
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Ordens de Serviço');
  });

  it('divisor entre cada par de grupos', async () => {
    await renderShell('admin');
    const nav = mainNav();
    const separators = within(nav).getAllByTestId('nav-separator');
    expect(separators).toHaveLength(within(nav).getAllByRole('group').length - 1);
    separators.forEach((hr) => {
      expect(hr.tagName).toBe('HR');
      expect(hr).toHaveAttribute('aria-hidden', 'true');
      expect(hr.nextElementSibling).toHaveAttribute('role', 'group');
    });
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = await renderShell('admin');
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('AppLayout — mobile (<768)', () => {
  beforeEach(() => setViewport(375));

  it('sem sidebar fixa; hambúrguer abre o menu em drawer com usuário e navegação', async () => {
    const user = userEvent.setup();
    const { location } = await renderShell('leader');
    expect(screen.queryByTestId('sidebar-panel')).toBeNull();
    expect(screen.queryByRole('navigation', { name: 'Navegação principal' })).toBeNull();
    // No mobile o avatar substitui o antigo botão de sair.
    expect(screen.getByRole('button', { name: /^Menu do usuário/ })).toHaveClass(
      'min-h-12',
      'min-w-12',
    );
    expect(screen.queryByRole('button', { name: 'Sair' })).toBeNull();

    const toggle = screen.getByRole('button', { name: 'Abrir menu' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await user.click(toggle);
    const dialog = screen.getByRole('dialog', { name: 'Menu' });
    expect(dialog).toHaveTextContent('Ana Souza');
    expect(dialog).toHaveTextContent('Líder/Plantonista');

    await user.click(within(dialog).getByRole('link', { name: 'Painel de Parâmetros' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(location()).toBe('/parametros');
  });

  it('ESC fecha o menu e devolve o foco ao hambúrguer', async () => {
    const user = userEvent.setup();
    await renderShell('admin');
    const toggle = screen.getByRole('button', { name: 'Abrir menu' });
    await user.click(toggle);
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(toggle).toHaveFocus());
  });

  it('ao crescer para desktop o drawer some e a sidebar fixa aparece', async () => {
    const user = userEvent.setup();
    await renderShell('admin');
    await user.click(screen.getByRole('button', { name: 'Abrir menu' }));
    expect(screen.getByRole('dialog', { name: 'Menu' })).toBeInTheDocument();
    act(() => setViewport(1280));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(screen.getByTestId('sidebar-panel')).toHaveClass('w-80');
    act(() => setViewport(375));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('não tem violações de acessibilidade (menu aberto)', async () => {
    const user = userEvent.setup();
    await renderShell('admin');
    await user.click(screen.getByRole('button', { name: 'Abrir menu' }));
    await screen.findByRole('dialog');
    expect(await axe(document.body)).toHaveNoViolations();
  });
});
