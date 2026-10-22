import { changedFieldsSummary, diffRows, scalarText } from './diff';
import { actorText, entityText } from './describe';

describe('diff old/new', () => {
  it('une os campos na ordem (old primeiro) e marca os lados existentes', () => {
    expect(diffRows({ old_values: { a: 1, b: null }, new_values: { b: 2, c: 'x' } })).toEqual([
      { field: 'a', before: 1, after: undefined, hasBefore: true, hasAfter: false },
      { field: 'b', before: null, after: 2, hasBefore: true, hasAfter: true },
      { field: 'c', before: undefined, after: 'x', hasBefore: false, hasAfter: true },
    ]);
    expect(diffRows({ old_values: null, new_values: null })).toEqual([]);
  });

  it('valores escalares legíveis', () => {
    expect(scalarText(null)).toBe('nulo');
    expect(scalarText('')).toBe('vazio');
    expect(scalarText(true)).toBe('Sim');
    expect(scalarText(false)).toBe('Não');
    expect(scalarText(0)).toBe('0');
    expect(scalarText('texto')).toBe('texto');
  });

  it('resumo da coluna: até 3 campos', () => {
    const five = { a: 1, b: 1, code: 1, name: 1, e: 1 };
    expect(changedFieldsSummary({ old_values: null, new_values: five })).toBe(
      'a, b, Código e mais 2',
    );
    expect(changedFieldsSummary({ old_values: null, new_values: null })).toBe('—');
  });

  it('ator e entidade', () => {
    expect(actorText({ id: 3, name: null, role: null })).toBe('Usuário #3');
    expect(actorText({ id: 3, name: 'Ana', role: null })).toBe('Ana');
    expect(entityText({ type: 'work_order', id: null })).toBe('work_order');
    expect(entityText({ type: 'user', id: 4 })).toBe('Usuário #4');
  });
});
