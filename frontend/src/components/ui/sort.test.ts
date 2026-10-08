import { nextSort } from './sort';

describe('nextSort', () => {
  it('cicla asc → desc → sem ordenação', () => {
    expect(nextSort(null, 'code')).toEqual({ field: 'code', direction: 'asc' });
    expect(nextSort({ field: 'code', direction: 'asc' }, 'code')).toEqual({
      field: 'code',
      direction: 'desc',
    });
    expect(nextSort({ field: 'code', direction: 'desc' }, 'code')).toBeNull();
  });

  it('trocar de coluna recomeça em asc', () => {
    expect(nextSort({ field: 'code', direction: 'desc' }, 'name')).toEqual({
      field: 'name',
      direction: 'asc',
    });
  });
});
