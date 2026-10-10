import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Truck } from 'lucide-react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { NavGroup, NavItem, Sidebar } from './Sidebar';

function Location() {
  return <p data-testid="location">{useLocation().pathname}</p>;
}

function renderSidebar(initial = '/') {
  return render(
    <MemoryRouter initialEntries={[initial]}>
      <Sidebar>
        <NavGroup title="Gestão de Ativos & Manutenção">
          <NavItem label="Frotas & Equipamentos" to="/ativos/equipamentos" icon={<Truck />} />
          <NavItem label="Compartimentos & Lubrificação" to="/ativos/compartimentos" disabled />
        </NavGroup>
      </Sidebar>
      <Routes>
        <Route path="*" element={<Location />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('Sidebar / NavItem', () => {
  it('nav principal com grupo rotulado', () => {
    renderSidebar();
    expect(screen.getByRole('navigation', { name: 'Navegação principal' })).toBeInTheDocument();
    expect(
      screen.getByRole('group', { name: 'Gestão de Ativos & Manutenção' }),
    ).toBeInTheDocument();
  });

  it('item habilitado navega e marca aria-current; área mínima de 48px', async () => {
    const user = userEvent.setup();
    renderSidebar();
    const link = screen.getByRole('link', { name: 'Frotas & Equipamentos' });
    expect(link).toHaveClass('min-h-12', 'min-w-12');
    await user.click(link);
    expect(screen.getByTestId('location')).toHaveTextContent('/ativos/equipamentos');
    expect(link).toHaveAttribute('aria-current', 'page');
  });

  it('item desabilitado: aria-disabled, "Em breve", focável, tooltip e sem navegação', async () => {
    const user = userEvent.setup();
    renderSidebar();
    const item = screen.getByRole('button', { name: /Compartimentos & Lubrificação/ });
    expect(item).toHaveAttribute('aria-disabled', 'true');
    expect(item).toHaveTextContent('Em breve');
    expect(item).toHaveClass('min-h-12', 'min-w-12');
    await user.tab();
    await user.tab();
    expect(item).toHaveFocus();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Disponível em fase futura');
    await user.keyboard('{Enter}');
    await user.click(item);
    expect(screen.getByTestId('location')).toHaveTextContent('/');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = renderSidebar('/ativos/equipamentos');
    expect(await axe(container)).toHaveNoViolations();
  });
});
