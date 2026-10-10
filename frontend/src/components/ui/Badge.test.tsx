import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import { Badge, StatusPill } from './Badge';

describe('Badge', () => {
  it('renderiza o texto com o tom', () => {
    render(<Badge tone="success">Definido nesta filial</Badge>);
    expect(screen.getByText('Definido nesta filial')).toHaveClass(
      'bg-success-subtle',
      'text-success',
    );
  });
});

describe('StatusPill', () => {
  it.each([
    ['active', 'Ativo'],
    ['inactive', 'Inativo'],
    ['disposed', 'Baixado'],
  ] as const)('status %s mostra texto "%s" (não só cor)', (status, label) => {
    render(<StatusPill status={status} />);
    expect(screen.getByText(label)).toBeInTheDocument();
  });

  it('aceita rótulo customizado', () => {
    render(<StatusPill status="active" label="Operando" />);
    expect(screen.getByText('Operando')).toBeInTheDocument();
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <p>
        <Badge>Neutro</Badge>
        <Badge tone="info">Info</Badge>
        <Badge tone="warning">Alerta</Badge>
        <StatusPill status="active" />
        <StatusPill status="inactive" />
        <StatusPill status="disposed" />
      </p>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
