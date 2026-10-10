import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Tabs, TabsContent, TabsList, TabsTrigger } from './Tabs';

function Example() {
  return (
    <Tabs defaultValue="geral">
      <TabsList aria-label="Seções do equipamento">
        <TabsTrigger value="geral">Visão Geral</TabsTrigger>
        <TabsTrigger value="financeiro">Financeiro</TabsTrigger>
        <TabsTrigger value="os" disabled>
          Histórico OS
        </TabsTrigger>
      </TabsList>
      <TabsContent value="geral">Conteúdo geral</TabsContent>
      <TabsContent value="financeiro">Conteúdo financeiro</TabsContent>
    </Tabs>
  );
}

describe('Tabs', () => {
  it('todas as abas têm área mínima de 48px', () => {
    render(<Example />);
    screen.getAllByRole('tab').forEach((tab) => expect(tab).toHaveClass('min-h-12', 'min-w-12'));
  });

  it('troca de aba por clique', async () => {
    const user = userEvent.setup();
    render(<Example />);
    await user.click(screen.getByRole('tab', { name: 'Financeiro' }));
    expect(screen.getByRole('tab', { name: 'Financeiro' })).toHaveAttribute(
      'aria-selected',
      'true',
    );
    expect(screen.getByRole('tabpanel')).toHaveTextContent('Conteúdo financeiro');
  });

  it('navega por setas', async () => {
    const user = userEvent.setup();
    render(<Example />);
    await user.tab();
    expect(screen.getByRole('tab', { name: 'Visão Geral' })).toHaveFocus();
    await user.keyboard('{ArrowRight}');
    expect(screen.getByRole('tab', { name: 'Financeiro' })).toHaveFocus();
    expect(screen.getByRole('tabpanel')).toHaveTextContent('Conteúdo financeiro');
  });

  it('aba desabilitada: aria-disabled, selo "Em breve" e não ativa', async () => {
    const user = userEvent.setup();
    render(<Example />);
    const disabled = screen.getByRole('tab', { name: /Histórico OS/ });
    expect(disabled).toHaveAttribute('aria-disabled', 'true');
    expect(disabled).toHaveTextContent('Em breve');
    await user.click(disabled);
    expect(disabled).toHaveAttribute('aria-selected', 'false');
    expect(screen.getByRole('tabpanel')).toHaveTextContent('Conteúdo geral');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(<Example />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
