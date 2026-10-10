import { describe, expect, it } from 'vitest';
import { SPACE_UNIT, TAP_MIN, TAP_MIN_CLASSES } from './tokens';

describe('tokens de design (G.1)', () => {
  it('TAP_MIN é 48px e as classes Tailwind equivalem (12 × 4px)', () => {
    expect(TAP_MIN).toBe(48);
    expect(TAP_MIN_CLASSES).toBe('min-h-12 min-w-12');
    expect(12 * 4).toBe(TAP_MIN);
  });

  it('escala 8pt', () => {
    expect(SPACE_UNIT).toBe(8);
    expect(TAP_MIN % SPACE_UNIT).toBe(0);
  });
});
