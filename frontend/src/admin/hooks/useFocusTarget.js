// frontend/src/admin/hooks/useFocusTarget.js
import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { parseFocus } from '../utils/focusParam';

// Reads ?focus=<type>:<id> for the given type. Returns the id (string) once,
// then strips the param (replace) so reload/back-navigation does not re-fire.
export function useFocusTarget(type) {
  const location = useLocation();
  const navigate = useNavigate();
  const [focusId, setFocusId] = useState(() => parseFocus(location.search, type));

  useEffect(() => {
    const id = parseFocus(location.search, type);
    if (!id) return;
    setFocusId(id);
    const params = new URLSearchParams(location.search);
    params.delete('focus');
    const search = params.toString();
    navigate(
      { pathname: location.pathname, search: search ? `?${search}` : '' },
      { replace: true }
    );
  }, [location.search, location.pathname, type, navigate]);

  return focusId;
}
