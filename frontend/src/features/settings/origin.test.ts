import { isDefinedHere, isOverrideHere, originLabel } from './origin';

const global = { type: 'global', id: null } as const;
const branch1 = { type: 'branch', id: 1 } as const;
const family1 = { type: 'family', id: 1 } as const;

describe('origem do valor efetivo (G.4-3)', () => {
  it.each([
    ['default', global, 'Padrão do sistema'],
    ['default', branch1, 'Padrão do sistema'],
    [{ scope_type: 'global', scope_id: null }, global, 'Definido no global'],
    [{ scope_type: 'global', scope_id: null }, branch1, 'Herdado de Global'],
    [{ scope_type: 'global', scope_id: null }, family1, 'Herdado de Global'],
    [{ scope_type: 'branch', scope_id: 1 }, branch1, 'Definido nesta filial'],
    [{ scope_type: 'family', scope_id: 1 }, family1, 'Definido nesta família'],
    [{ scope_type: 'branch', scope_id: 2 }, branch1, 'Herdado da filial'],
  ] as const)('%j visto em %j → %s', (source, scope, label) => {
    expect(originLabel(source, scope)).toBe(label);
  });

  it('override removível só no próprio escopo e nunca no global', () => {
    const atBranch = { scope_type: 'branch', scope_id: 1 } as const;
    expect(isOverrideHere(atBranch, branch1)).toBe(true);
    expect(isOverrideHere(atBranch, { type: 'branch', id: 2 })).toBe(false);
    expect(isOverrideHere({ scope_type: 'global', scope_id: null }, global)).toBe(false);
    expect(isDefinedHere({ scope_type: 'global', scope_id: null }, global)).toBe(true);
    expect(isOverrideHere('default', branch1)).toBe(false);
  });
});
