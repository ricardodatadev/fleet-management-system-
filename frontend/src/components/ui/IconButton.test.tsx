import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Pencil } from 'lucide-react';
import { IconButton } from './IconButton';

describe('IconButton', () => {
  it('usa o label como nome acessível e tem área mínima de 48px', () => {
    render(<IconButton label="Editar" icon={<Pencil />} />);
    const button = screen.getByRole('button', { name: 'Editar' });
    expect(button).toHaveAttribute('title', 'Editar');
    expect(button).toHaveClass('min-h-12', 'min-w-12');
  });

  it('é acionável por teclado', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<IconButton label="Editar" icon={<Pencil />} onClick={onClick} />);
    await user.tab();
    expect(screen.getByRole('button')).toHaveFocus();
    await user.keyboard('{Enter}');
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(<IconButton label="Editar" icon={<Pencil />} />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
