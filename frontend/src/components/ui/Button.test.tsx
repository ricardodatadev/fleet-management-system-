import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Button } from './Button';

describe('Button', () => {
  it('renderiza com type="button" e área mínima de 48px', () => {
    render(<Button>Salvar</Button>);
    const button = screen.getByRole('button', { name: 'Salvar' });
    expect(button).toHaveAttribute('type', 'button');
    expect(button).toHaveClass('min-h-12', 'min-w-12');
  });

  it('dispara onClick por clique e por teclado (Enter e Espaço)', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Salvar</Button>);
    await user.tab();
    expect(screen.getByRole('button')).toHaveFocus();
    await user.keyboard('{Enter}');
    await user.keyboard(' ');
    await user.click(screen.getByRole('button'));
    expect(onClick).toHaveBeenCalledTimes(3);
  });

  it('loading bloqueia cliques e marca aria-busy', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(
      <Button loading onClick={onClick}>
        Salvar
      </Button>,
    );
    const button = screen.getByRole('button', { name: 'Salvar' });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-busy', 'true');
    await user.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('aplica a variante', () => {
    render(<Button variant="danger">Excluir</Button>);
    expect(screen.getByRole('button')).toHaveClass('bg-danger');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <>
        <Button>Primário</Button>
        <Button variant="secondary">Secundário</Button>
        <Button variant="danger">Perigo</Button>
        <Button variant="ghost">Fantasma</Button>
        <Button loading>Carregando</Button>
      </>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
