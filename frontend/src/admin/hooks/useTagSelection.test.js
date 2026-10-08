import { renderHook, act } from '@testing-library/react';
import { useTagSelection } from './useTagSelection';

test('it toggles ids on and off', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.toggle(7));
  expect(result.current.isSelected(7)).toBe(true);
  expect(result.current.count).toBe(1);

  act(() => result.current.toggle(7));
  expect(result.current.isSelected(7)).toBe(false);
  expect(result.current.count).toBe(0);
});

/* The grid pages; the selection does not. A clerk who ticks four cartons on
   page one and three on page two is printing seven labels. */
test('a selection survives a page change', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.toggle(1));
  act(() => result.current.toggle(2));

  // page 2 renders entirely different rows; nothing tells the hook
  act(() => result.current.toggle(90));

  expect(result.current.count).toBe(3);
  expect(result.current.selectedIds).toEqual([1, 2, 90]);
});

test('selectAll replaces the selection with the given ids', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.toggle(1));
  act(() => result.current.selectAll([5, 6, 7]));

  expect(result.current.selectedIds).toEqual([5, 6, 7]);
});

test('clear empties it', () => {
  const { result } = renderHook(() => useTagSelection());

  act(() => result.current.selectAll([1, 2, 3]));
  act(() => result.current.clear());

  expect(result.current.count).toBe(0);
});
