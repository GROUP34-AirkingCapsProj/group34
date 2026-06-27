// frontend/src/admin/utils/focusParam.test.js
import { parseFocus } from './focusParam';

describe('parseFocus', () => {
  it('returns the id when type matches', () => {
    expect(parseFocus('?focus=po:42', 'po')).toBe('42');
    expect(parseFocus('focus=inv:7&tab=x', 'inv')).toBe('7');
  });

  it('returns null when the type does not match', () => {
    expect(parseFocus('?focus=po:42', 'pr')).toBeNull();
  });

  it('returns null for missing/malformed focus', () => {
    expect(parseFocus('', 'po')).toBeNull();
    expect(parseFocus('?tab=status', 'po')).toBeNull();
    expect(parseFocus('?focus=po', 'po')).toBeNull();
    expect(parseFocus('?focus=po:', 'po')).toBeNull();
  });
});
