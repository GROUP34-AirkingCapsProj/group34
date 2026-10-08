import { useCallback, useMemo, useState } from 'react';

/**
 * Which tags are ticked, held as ids rather than rows.
 *
 * Ids, because the grid pages: the row objects for page one are gone by the
 * time page two renders, and a selection built from them would empty itself
 * every time the clerk turned a page. A Set of ids outlives the rows it came
 * from, so ticking four cartons on one page and three on the next prints
 * seven labels.
 */
export function useTagSelection() {
  const [selected, setSelected] = useState(() => new Set());

  const toggle = useCallback((serialId) => {
    setSelected((current) => {
      const next = new Set(current);

      if (next.has(serialId)) {
        next.delete(serialId);
      } else {
        next.add(serialId);
      }

      return next;
    });
  }, []);

  /* Replaces rather than merges: this backs "select all matching filters",
     which is a statement about the whole filtered set, not an addition to
     whatever was ticked before it. */
  const selectAll = useCallback((serialIds) => {
    setSelected(new Set(serialIds));
  }, []);

  const clear = useCallback(() => setSelected(new Set()), []);

  const isSelected = useCallback((serialId) => selected.has(serialId), [selected]);

  const selectedIds = useMemo(() => Array.from(selected), [selected]);

  return {
    selectedIds,
    count: selected.size,
    isSelected,
    toggle,
    selectAll,
    clear,
  };
}

export default useTagSelection;
