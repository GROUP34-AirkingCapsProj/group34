import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { auditEvidenceAPI } from '../services/api';

const firstOfThisMonth = () => {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
};

const today = () => new Date().toISOString().slice(0, 10);

/**
 * The Explorer's scope lives in the URL, not in component state.
 *
 * An auditor's output is a finding, and a finding needs a citation — so the view
 * behind it has to be linkable. Keeping the scope in the query string means a
 * pasted URL reopens exactly the evidence the auditor was looking at, which is
 * what lets them attach it to a report.
 */
export function useAuditEvidence() {
  const [searchParams, setSearchParams] = useSearchParams();

  const scope = useMemo(
    () => ({
      from: searchParams.get('from') || firstOfThisMonth(),
      to: searchParams.get('to') || today(),
      lens: searchParams.get('lens') === 'finance' ? 'finance' : 'inventory',
      location_id: searchParams.get('location_id') || '',
      product_id: searchParams.get('product_id') || '',
      category_id: searchParams.get('category_id') || '',
      user_id: searchParams.get('user_id') || '',
      movement_type: searchParams.get('movement_type') || '',
      dimension: searchParams.get('dimension') || 'movement_type',
    }),
    [searchParams]
  );

  const setScope = useCallback(
    (patch) => {
      const next = new URLSearchParams(searchParams);
      Object.entries(patch).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) next.delete(key);
        else next.set(key, String(value));
      });
      setSearchParams(next, { replace: true });
    },
    [searchParams, setSearchParams]
  );

  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');

    auditEvidenceAPI
      .getSummary(scope)
      .then((res) => {
        if (cancelled) return;
        setSummary(res?.data ?? null);
      })
      .catch((e) => {
        if (cancelled) return;
        // A refusal is shown as a refusal. Rendering zeros here would let a
        // rejected query read as a period with no activity in it — the exact
        // confusion between "nothing happened" and "we could not tell you"
        // that this feature exists to remove.
        setSummary(null);
        setError(e?.message || 'Could not load the summary for this period.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [scope]);

  return { scope, setScope, summary, loading, error };
}

export default useAuditEvidence;
