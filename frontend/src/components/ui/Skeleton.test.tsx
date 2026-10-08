import { render } from '@testing-library/react';
import { axe } from 'jest-axe';
import { Skeleton } from './Skeleton';

describe('Skeleton', () => {
  it('é decorativo (aria-hidden) e aceita classes', () => {
    const { container } = render(<Skeleton className="h-8 w-32" />);
    const el = container.firstElementChild;
    expect(el).toHaveAttribute('aria-hidden', 'true');
    expect(el).toHaveClass('animate-pulse', 'h-8', 'w-32');
  });

  it('não tem violações de acessibilidade', async () => {
    const { container } = render(
      <div aria-busy="true">
        <Skeleton />
      </div>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
