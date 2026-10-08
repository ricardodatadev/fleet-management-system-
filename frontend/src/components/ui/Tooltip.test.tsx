import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Button } from './Button';
import { Tooltip } from './Tooltip';

describe('Tooltip', () => {
  it('aparece ao focar pelo teclado e some com ESC', async () => {
    const user = userEvent.setup();
    render(
      <Tooltip content="Disponível em fase futura">
        <Button>Telemetria</Button>
      </Tooltip>,
    );
    await user.tab();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Disponível em fase futura');
    expect(screen.getByRole('button', { name: 'Telemetria' })).toHaveAccessibleDescription(
      'Disponível em fase futura',
    );
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('não tem violações de acessibilidade (aberto)', async () => {
    const user = userEvent.setup();
    render(
      <Tooltip content="Ajuda">
        <Button>Abrir</Button>
      </Tooltip>,
    );
    await user.tab();
    await screen.findByRole('tooltip');
    // O conteúdo vai para um portal no <body>; `region` (landmarks) é regra de página, não do componente.
    expect(
      await axe(document.body, { rules: { region: { enabled: false } } }),
    ).toHaveNoViolations();
  });
});
