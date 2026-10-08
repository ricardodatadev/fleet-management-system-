import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Input, Textarea } from './Input';

describe('Input', () => {
  it('aceita digitação e tem área mínima de 48px', async () => {
    const user = userEvent.setup();
    render(<Input aria-label="Nome" />);
    const input = screen.getByRole('textbox', { name: 'Nome' });
    expect(input).toHaveClass('min-h-12', 'min-w-12');
    await user.type(input, 'Caminhão 01');
    expect(input).toHaveValue('Caminhão 01');
  });

  it('invalid marca aria-invalid e borda de erro', () => {
    render(<Input aria-label="Placa" invalid />);
    const input = screen.getByRole('textbox');
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveClass('border-danger-600');
  });

  it('desabilitado não recebe foco', async () => {
    const user = userEvent.setup();
    render(<Input aria-label="Nome" disabled />);
    await user.tab();
    expect(screen.getByRole('textbox')).not.toHaveFocus();
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <>
        <Input aria-label="Nome" />
        <Input aria-label="Placa" invalid />
      </>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('Textarea', () => {
  it('renderiza com 4 linhas por padrão e aceita digitação', async () => {
    const user = userEvent.setup();
    render(<Textarea aria-label="Observações" />);
    const textarea = screen.getByRole('textbox', { name: 'Observações' });
    expect(textarea).toHaveAttribute('rows', '4');
    expect(textarea).toHaveClass('min-h-12');
    await user.type(textarea, 'linha 1{Enter}linha 2');
    expect(textarea).toHaveValue('linha 1\nlinha 2');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(<Textarea aria-label="Observações" invalid />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
