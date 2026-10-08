import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { RadioGroup } from './RadioGroup';

const options = [
  { value: 'block', label: 'Bloquear' },
  { value: 'warn', label: 'Apenas alertar' },
  { value: 'off', label: 'Desligado', disabled: true },
];

describe('RadioGroup', () => {
  it('renderiza as opções com área mínima de 48px', () => {
    render(<RadioGroup aria-label="Política" options={options} />);
    const radios = screen.getAllByRole('radio');
    expect(radios).toHaveLength(3);
    radios.forEach((radio) => expect(radio).toHaveClass('min-h-12', 'min-w-12'));
    expect(screen.getByRole('radio', { name: 'Desligado' })).toBeDisabled();
  });

  it('seleciona por clique no rótulo', async () => {
    const user = userEvent.setup();
    const onValueChange = vi.fn();
    render(<RadioGroup aria-label="Política" options={options} onValueChange={onValueChange} />);
    await user.click(screen.getByText('Apenas alertar'));
    expect(screen.getByRole('radio', { name: 'Apenas alertar' })).toBeChecked();
    expect(onValueChange).toHaveBeenCalledWith('warn');
  });

  it('navega com setas, pulando opção desabilitada', async () => {
    const user = userEvent.setup();
    render(<RadioGroup aria-label="Política" options={options} defaultValue="block" />);
    await user.tab();
    expect(screen.getByRole('radio', { name: 'Bloquear' })).toHaveFocus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('radio', { name: 'Apenas alertar' })).toHaveFocus();
    await user.keyboard(' ');
    expect(screen.getByRole('radio', { name: 'Apenas alertar' })).toBeChecked();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('radio', { name: 'Bloquear' })).toHaveFocus();
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <RadioGroup aria-label="Política" options={options} defaultValue="warn" />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
