import PropTypes from 'prop-types';

/**
 * Displays the resolved location for strict-role users (warehouse_personnel,
 * branch_manager) or "All Locations" for centralized roles (admin,
 * inventory_analyst, auditor). Read-only — no interaction.
 *
 * Usage A — header badge (centralized/scoped indicator):
 *   <LocationBadge isCentralized={true} />
 *   <LocationBadge isCentralized={false} locationName="Cebu Branch" />
 *
 * Usage B — legacy page badge (always scoped, shows locationType too):
 *   <LocationBadge locationName="Cebu Branch" locationType="Branch" />
 *
 * When `isCentralized` is explicitly provided, the component branches on it.
 * Otherwise it falls back to the legacy rendering (locationName or "No location assigned").
 */
export default function LocationBadge({ isCentralized, locationName, locationType, className = '' }) {
  // ── Header-badge mode: isCentralized was explicitly passed ──────────────
  if (isCentralized !== undefined) {
    const display = isCentralized
      ? 'All Locations'
      : `Showing data for: ${locationName ?? 'Unknown location'}`;

    return (
      <div className={`location-badge ${className}`}>
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="14"
          height="14"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z" />
          <circle cx="12" cy="10" r="3" />
        </svg>
        <span className="location-badge__text">{display}</span>
      </div>
    );
  }

  // ── Legacy page-badge mode: just show locationName + locationType ────────
  const display = locationName ?? 'No location assigned';
  const typeLabel = locationType ? ` (${locationType})` : '';

  return (
    <div className={`location-badge ${className}`}>
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width="14"
        height="14"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z" />
        <circle cx="12" cy="10" r="3" />
      </svg>
      <span className="location-badge__text">
        {display}{typeLabel}
      </span>
    </div>
  );
}

LocationBadge.propTypes = {
  /** When provided, switches to header-badge mode. true = centralized roles, false = scoped roles. */
  isCentralized: PropTypes.bool,
  locationName:  PropTypes.string,
  locationType:  PropTypes.string,
  className:     PropTypes.string,
};
