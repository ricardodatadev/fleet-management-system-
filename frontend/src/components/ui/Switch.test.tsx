import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Switch } from './Switch';

describe('Switch', () => {
  it('alterna por clique no rótulo e tem área mínima de 48px', async () => {
    const user = userEvent.setup();
    const onCheckedChange = vi.fn();
    render(<Switch label="Exigir checklist" onCheckedChange={onCheckedChange} />);
    const control = screen.getByRole('switch', { name: 'Exigir checklist' });
    expect(control).toHaveClass('min-h-12', 'min-w-12');
    expect(control).toHaveAttribute('aria-checked', 'false');
    await user.click(screen.getByText('Exigir checklist'));
    expect(control).toHaveAttribute('aria-checked', 'true');
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('alterna pelo teclado (Espaço)', async () => {
    const user = userEvent.setup();
    render(<Switch label="Ativo" defaultChecked />);
    await user.tab();
    await user.keyboard(' ');
    expect(screen.getByRole('switch')).toHaveAttribute('aria-checked', 'false');
  });

  it('desabilitado não alterna', async () => {
    const user = userEvent.setup();
    render(<Switch label="Ativo" disabled />);
    await user.click(screen.getByRole('switch'));
    expect(screen.getByRole('switch')).toHaveAttribute('aria-checked', 'false');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <>
        <Switch label="Com rótulo" />
        <Switch aria-label="Só aria-label" defaultChecked />
      </>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
