import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { useState } from 'react';
import { Button } from './Button';
import { Drawer, Modal } from './Modal';

function Harness({ as: Panel }: { as: typeof Modal }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button onClick={() => setOpen(true)}>Abrir</Button>
      <Panel
        open={open}
        onOpenChange={setOpen}
        title="Cadastrar frota"
        footer={<Button>Salvar</Button>}
      >
        <input aria-label="Código" />
      </Panel>
    </>
  );
}

describe.each([
  ['Modal', Modal],
  ['Drawer', Drawer],
])('%s', (_name, Panel) => {
  it('abre como dialog com título, rodapé e botão Fechar de 48px', async () => {
    const user = userEvent.setup();
    render(<Harness as={Panel} />);
    await user.click(screen.getByRole('button', { name: 'Abrir' }));
    const dialog = screen.getByRole('dialog', { name: 'Cadastrar frota' });
    expect(dialog).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Salvar' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Fechar' })).toHaveClass('min-h-12', 'min-w-12');
  });

  it('fecha com ESC e devolve o foco ao gatilho', async () => {
    const user = userEvent.setup();
    render(<Harness as={Panel} />);
    const trigger = screen.getByRole('button', { name: 'Abrir' });
    await user.click(trigger);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it('fecha pelo botão Fechar', async () => {
    const user = userEvent.setup();
    render(<Harness as={Panel} />);
    await user.click(screen.getByRole('button', { name: 'Abrir' }));
    await user.click(screen.getByRole('button', { name: 'Fechar' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('prende o foco dentro do painel', async () => {
    const user = userEvent.setup();
    render(<Harness as={Panel} />);
    await user.click(screen.getByRole('button', { name: 'Abrir' }));
    const dialog = screen.getByRole('dialog');
    for (let i = 0; i < 5; i += 1) {
      await user.tab();
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
  });

  it('não tem violações de acessibilidade (aberto)', async () => {
    const user = userEvent.setup();
    render(<Harness as={Panel} />);
    await user.click(screen.getByRole('button', { name: 'Abrir' }));
    expect(await axe(document.body)).toHaveNoViolations();
  });
});
