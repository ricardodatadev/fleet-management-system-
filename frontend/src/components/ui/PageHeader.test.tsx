import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import { Button } from './Button';
import { PageHeader } from './PageHeader';

describe('PageHeader', () => {
  it('renderiza h1, descrição e ações', () => {
    render(
      <PageHeader
        title="Frotas & Equipamentos"
        description="Cadastro de ativos"
        actions={<Button>Cadastrar Nova Frota</Button>}
      />,
    );
    expect(
      screen.getByRole('heading', { level: 1, name: 'Frotas & Equipamentos' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Cadastro de ativos')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cadastrar Nova Frota' })).toBeInTheDocument();
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(<PageHeader title="Parâmetros" />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
