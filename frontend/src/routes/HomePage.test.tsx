import { render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import { HomePage } from './HomePage';

describe('HomePage', () => {
  it('mostra o placeholder SIGOF-M', () => {
    render(<HomePage />);
    expect(screen.getByRole('heading', { level: 1, name: 'SIGOF-M' })).toBeInTheDocument();
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(<HomePage />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
