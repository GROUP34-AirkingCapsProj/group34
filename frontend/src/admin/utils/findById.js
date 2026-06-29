// frontend/src/admin/utils/findById.js
// Find an item in `list` by matching any of `idFields` against `focusId`
// using a loose string comparison. Returns the item or null.
export function findById(list, idFields, focusId) {
  if (!Array.isArray(list) || focusId == null) return null;
  return (
    list.find((row) =>
      idFields.some((f) => row?.[f] != null && String(row[f]) === String(focusId))
    ) || null
  );
}
