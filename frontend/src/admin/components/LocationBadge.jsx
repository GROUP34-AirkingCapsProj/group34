import PropTypes from 'prop-types';

/**
 * Displays the resolved location for strict-role users (warehouse_personnel,
 * inventory_analyst). Read-only — no interaction.
 */
export default function LocationBadge({ locationName, locationType, className = '' }) {
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
  locationName: PropTypes.string,
  locationType: PropTypes.string,
  className:    PropTypes.string,
};
