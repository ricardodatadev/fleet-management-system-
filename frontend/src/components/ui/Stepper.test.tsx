import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Stepper, Wizard } from './Stepper';

describe('Stepper', () => {
  it('marca o passo atual e os concluídos', () => {
    render(<Stepper steps={['Gerais', 'Financeiro', 'Revisão']} current={1} />);
    const items = screen.getAllByRole('listitem');
    expect(items[0]).toHaveTextContent('(concluída)');
    expect(items[1]).toHaveAttribute('aria-current', 'step');
    expect(items[2]).not.toHaveAttribute('aria-current');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(<Stepper steps={['A', 'B']} current={0} />);
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('Wizard', () => {
  const steps = [
    { label: 'Gerais', content: <p>Passo gerais</p> },
    { label: 'Financeiro', content: <p>Passo financeiro</p> },
  ];

  it('avança, volta e conclui no último passo', async () => {
    const user = userEvent.setup();
    const onFinish = vi.fn();
    render(<Wizard steps={steps} onFinish={onFinish} />);
    expect(screen.getByText('Passo gerais')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Voltar' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Avançar' }));
    expect(screen.getByText('Passo financeiro')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Voltar' }));
    expect(screen.getByText('Passo gerais')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Avançar' }));
    await user.click(screen.getByRole('button', { name: 'Concluir' }));
    expect(onFinish).toHaveBeenCalledTimes(1);
  });

  it('canAdvance bloqueia o avanço', () => {
    render(<Wizard steps={steps} onFinish={() => {}} canAdvance={() => false} />);
    expect(screen.getByRole('button', { name: 'Avançar' })).toBeDisabled();
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(<Wizard steps={steps} onFinish={() => {}} />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
