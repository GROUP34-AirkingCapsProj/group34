// frontend/src/admin/utils/focusParam.js
// Parse a `?focus=<type>:<id>` query string. Returns the id (string) when the
// type segment matches the requested `type`, otherwise null.
export function parseFocus(search, type) {
  const raw = new URLSearchParams(search).get('focus');
  if (!raw) return null;
  const idx = raw.indexOf(':');
  if (idx === -1) return null;
  const t = raw.slice(0, idx);
  const id = raw.slice(idx + 1);
  return t === type && id ? id : null;
}
