import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Select } from './Select';

const options = [
  { value: 'sp', label: 'São Paulo' },
  { value: 'rj', label: 'Rio de Janeiro' },
  { value: 'mg', label: 'Belo Horizonte', disabled: true },
];

describe('Select', () => {
  it('renderiza placeholder e opções, com área mínima de 48px', () => {
    render(<Select aria-label="Filial" options={options} placeholder="Todas" />);
    const select = screen.getByRole('combobox', { name: 'Filial' });
    expect(select).toHaveClass('min-h-12', 'min-w-12');
    expect(screen.getAllByRole('option')).toHaveLength(4);
    expect(screen.getByRole('option', { name: 'Todas' })).toHaveValue('');
    expect(screen.getByRole('option', { name: 'Belo Horizonte' })).toBeDisabled();
  });

  it('sem placeholder não cria opção vazia', () => {
    render(<Select aria-label="Filial" options={options} />);
    expect(screen.getAllByRole('option')).toHaveLength(3);
  });

  it('seleciona por interação do usuário', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Select aria-label="Filial" options={options} onChange={onChange} />);
    await user.selectOptions(screen.getByRole('combobox'), 'rj');
    expect(screen.getByRole('combobox')).toHaveValue('rj');
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('invalid marca aria-invalid', () => {
    render(<Select aria-label="Filial" options={options} invalid />);
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <Select aria-label="Filial" options={options} placeholder="Todas" />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
