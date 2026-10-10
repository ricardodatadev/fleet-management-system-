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

  it('obrigatório mostra o asterisco (oculto do leitor de tela) e aria-required', () => {
    const { container } = render(
      <FormField label="Placa" required>
        <Input />
      </FormField>,
    );
    const label = container.querySelector('label');
    expect(label).toHaveTextContent('Placa *');
    expect(label?.querySelector('[aria-hidden="true"]')).toHaveTextContent('*');
    // O nome acessível não inclui o asterisco.
    expect(screen.getByRole('textbox', { name: 'Placa' })).toHaveAttribute('aria-required', 'true');
  });

  it('hideRequiredMark: sem asterisco, mantendo aria-required (telas públicas, G.1)', () => {
    const { container } = render(
      <FormField label="Senha" required hideRequiredMark>
        <Input />
      </FormField>,
    );
    expect(container).not.toHaveTextContent('*');
    expect(screen.getByRole('textbox', { name: 'Senha' })).toHaveAttribute('aria-required', 'true');
  });

  it('erro igual à ajuda (422 que repete a regra) aparece uma vez só, como erro', () => {
    const rule = 'Use letras minúsculas, números e ponto.';
    const { container } = render(
      <FormField label="Usuário" help={rule} error={rule}>
        <Input />
      </FormField>,
    );
    const input = screen.getByRole('textbox', { name: 'Usuário' });
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription(rule);
    expect(container.textContent?.split(rule)).toHaveLength(2);
  });

  it('campo opcional não tem asterisco nem aria-required', () => {
    const { container } = render(
      <FormField label="Observações">
        <Input />
      </FormField>,
    );
    expect(container).not.toHaveTextContent('*');
    expect(screen.getByRole('textbox', { name: 'Observações' })).not.toHaveAttribute(
      'aria-required',
    );
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
