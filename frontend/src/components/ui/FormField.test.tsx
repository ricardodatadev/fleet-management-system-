import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import { Checkbox } from './Checkbox';
import { FormField } from './FormField';
import { Input } from './Input';
import { Select } from './Select';

describe('FormField', () => {
  it('associa rótulo, ajuda e obrigatoriedade ao controle (elemento)', () => {
    render(
      <FormField label="Placa" help="Formato Mercosul" required>
        <Input />
      </FormField>,
    );
    const input = screen.getByRole('textbox', { name: 'Placa' });
    expect(input).toHaveAttribute('aria-required', 'true');
    expect(input).not.toHaveAttribute('aria-invalid');
    expect(input).toHaveAccessibleDescription('Formato Mercosul');
  });

  it('obrigatório não renderiza asterisco, mantém só aria-required (G.1, v1.7)', () => {
    const { container } = render(
      <FormField label="Placa" required>
        <Input />
      </FormField>,
    );
    expect(container).not.toHaveTextContent('*');
    expect(screen.getByText('Placa').tagName).toBe('LABEL');
    expect(screen.getByRole('textbox', { name: 'Placa' })).toHaveAttribute('aria-required', 'true');
  });

  it('exibe erro (ex.: 422) e marca o controle como inválido', () => {
    render(
      <FormField label="Placa" help="Formato Mercosul" error="Placa já cadastrada.">
        <Input />
      </FormField>,
    );
    const input = screen.getByRole('textbox', { name: 'Placa' });
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription('Formato Mercosul Placa já cadastrada.');
    expect(screen.getByText('Placa já cadastrada.')).toBeInTheDocument();
  });

  it('aceita render prop', () => {
    render(
      <FormField label="Filial" error="Obrigatório">
        {(props) => <Select {...props} options={[{ value: '1', label: 'Matriz' }]} />}
      </FormField>,
    );
    expect(screen.getByRole('combobox', { name: 'Filial' })).toHaveAttribute(
      'aria-invalid',
      'true',
    );
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <form>
        <FormField label="Nome" required>
          <Input />
        </FormField>
        <FormField label="Placa" help="Formato Mercosul" error="Inválida">
          <Input />
        </FormField>
        <FormField label="Motorista">
          <Checkbox />
        </FormField>
      </form>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
