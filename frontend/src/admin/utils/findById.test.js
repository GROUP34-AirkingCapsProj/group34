// frontend/src/admin/utils/findById.test.js
import { findById } from './findById';

describe('findById', () => {
  const list = [
    { po_id: 10, name: 'A' },
    { po_id: 11, id: 99, name: 'B' },
  ];

  it('matches on the first candidate field (loose string compare)', () => {
    expect(findById(list, ['po_id', 'id'], '10')).toEqual({ po_id: 10, name: 'A' });
    expect(findById(list, ['po_id', 'id'], 11)).toEqual({ po_id: 11, id: 99, name: 'B' });
  });

  it('falls through to a later candidate field', () => {
    expect(findById(list, ['id'], 99)).toEqual({ po_id: 11, id: 99, name: 'B' });
  });

  it('returns null when not found or inputs are bad', () => {
    expect(findById(list, ['po_id'], 404)).toBeNull();
    expect(findById(null, ['po_id'], 10)).toBeNull();
    expect(findById(list, ['po_id'], null)).toBeNull();
  });
});
