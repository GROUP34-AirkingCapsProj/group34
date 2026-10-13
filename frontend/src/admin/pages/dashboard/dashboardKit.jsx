import React, { useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import { batchAPI } from '../../services/api';

export const PALETTE = {
  crimson: '#dc143c', crimsonSoft: 'rgba(220,20,60,0.12)',
  gold: '#f5b301', green: '#059669', greenSoft: 'rgba(5,150,105,0.12)',
  blue: '#2563eb', amber: '#d97706', gray: '#9ca3af', grid: '#eef1f7', ink: '#5b6472',
};

export const fmt = (n) => Number(n || 0).toLocaleString();

/**
 * Roles that read every location: admin=1, inventory_analyst=2,
 * branch_manager=3, auditor=5. Mirrors User::readsAllLocations() — the read
 * half of the scope, which is the half a dashboard cares about. The branch
 * manager still writes only at its own branch; see useUserLocation().
 */
export const readsAllLocations = (user) => [1, 2, 3, 5].includes(Number(user?.role?.role_id));

/**
 * Fetches the (server-scoped) dashboard payload only.
 * KPI counts come from dashboard stats / chart series — no 300-row inventory dump.
 */
export function useRoleDashboard() {
  const { user: _user } = useAuth();
  const user = _user || {};
  const readsAll = readsAllLocations(user);
  const locationId = readsAll ? null : (user?.location?.location_id ?? null);

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const dashRes = await batchAPI.get({ include: ['dashboard'] });
        if (!alive) return;
        const d = dashRes?.data?.dashboard ?? null;
        setData(d && d.success === false ? null : d);
      } catch (e) {
        if (alive) setError(e.message || 'Failed to load dashboard.');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => { alive = false; };
  }, [locationId]);

  return {
    user,
    centralized,
    locationName: user?.location?.location_name || user?.branch?.branch_name || 'All Locations',
    data,
    inv: [],
    loading,
    error,
  };
}

/** A single KPI stat card using the ak- design system. */
export function Kpi({ label, value, icon, tone = 'crimson' }) {
  return (
    <div className="ak-stat">
      <div className="ak-stat-icon" style={{ color: PALETTE[tone] || PALETTE.crimson, background: 'var(--ak-band)' }}>
        {icon}
      </div>
      <div>
        <div className="ak-stat-label">{label}</div>
        <div className="ak-stat-value">{value}</div>
      </div>
    </div>
  );
}

export const baseChartOptions = {
  responsive: true, maintainAspectRatio: false,
  plugins: { legend: { display: false }, tooltip: { backgroundColor: 'var(--fill-blue-900)', padding: 10, cornerRadius: 8 } },
  scales: {
    x: { grid: { display: false }, ticks: { color: PALETTE.ink, font: { size: 11 } } },
    y: { grid: { color: PALETTE.grid }, ticks: { color: PALETTE.ink, font: { size: 11 } }, beginAtZero: true },
  },
};
