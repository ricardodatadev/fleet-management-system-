import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Checkbox } from './Checkbox';

describe('Checkbox', () => {
  it('marca por clique no rótulo e tem área mínima de 48px', async () => {
    const user = userEvent.setup();
    const onCheckedChange = vi.fn();
    render(<Checkbox label="Motorista" onCheckedChange={onCheckedChange} />);
    const box = screen.getByRole('checkbox', { name: 'Motorista' });
    expect(box).toHaveClass('min-h-12', 'min-w-12');
    await user.click(screen.getByText('Motorista'));
    expect(box).toBeChecked();
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('alterna pelo teclado (Espaço)', async () => {
    const user = userEvent.setup();
    render(<Checkbox label="Motorista" />);
    await user.tab();
    await user.keyboard(' ');
    expect(screen.getByRole('checkbox')).toBeChecked();
  });

  it('suporta estado indeterminado', () => {
    render(<Checkbox label="Todos" checked="indeterminate" />);
    expect(screen.getByRole('checkbox')).toHaveAttribute('aria-checked', 'mixed');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <>
        <Checkbox label="Motorista" />
        <Checkbox label="Mecânico" defaultChecked />
        <Checkbox label="Bloqueado" disabled />
      </>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
