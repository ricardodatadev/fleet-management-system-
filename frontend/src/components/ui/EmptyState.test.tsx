import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import { Truck } from 'lucide-react';
import { Button } from './Button';
import { EmptyState } from './EmptyState';

describe('EmptyState', () => {
  it('renderiza título, descrição, ícone decorativo e ação', () => {
    render(
      <EmptyState
        title="Nenhuma frota cadastrada"
        description="Cadastre a primeira frota."
        icon={<Truck />}
        action={<Button>Cadastrar</Button>}
      />,
    );
    expect(screen.getByRole('heading', { name: 'Nenhuma frota cadastrada' })).toBeInTheDocument();
    expect(screen.getByText('Cadastre a primeira frota.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cadastrar' })).toBeInTheDocument();
  });

  it('role="alert" para estado de erro', () => {
    render(<EmptyState role="alert" title="Falha ao carregar" />);
    expect(screen.getByRole('alert')).toHaveTextContent('Falha ao carregar');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <main>
        <EmptyState role="status" title="Vazio" description="Nada aqui." icon={<Truck />} />
      </main>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
