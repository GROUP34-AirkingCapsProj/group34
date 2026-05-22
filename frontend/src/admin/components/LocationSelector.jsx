import PropTypes from 'prop-types';

/**
 * Location picker for admin and branch_manager roles.
 * Renders a <select> populated from `allowedLocations` (hydrated by refreshUser).
 * Falls back to a text input when allowedLocations is empty (pre-refresh or admin
 * with too many locations to enumerate).
 */
export default function LocationSelector({
  value,
  onChange,
  allowedLocations = [],
  disabled = false,
  error = '',
  className = '',
}) {
  const hasOptions = allowedLocations.length > 0;

  return (
    <div className={`location-selector ${className}`}>
      <label className="location-selector__label" htmlFor="location-selector-input">
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
        Location
      </label>

      {hasOptions ? (
        <select
          id="location-selector-input"
          className={`location-selector__select${error ? ' location-selector__select--error' : ''}`}
          value={value ?? ''}
          onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
          disabled={disabled}
        >
          <option value="">-- Select location --</option>
          {allowedLocations.map((loc) => (
            <option key={loc.location_id} value={loc.location_id}>
              {loc.location_name}
              {loc.location_type ? ` (${loc.location_type})` : ''}
            </option>
          ))}
        </select>
      ) : (
        <input
          id="location-selector-input"
          type="number"
          className={`location-selector__input${error ? ' location-selector__input--error' : ''}`}
          placeholder="Enter location ID"
          value={value ?? ''}
          onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
          disabled={disabled}
          min="1"
        />
      )}

      {error && (
        <p className="location-selector__error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

LocationSelector.propTypes = {
  value:            PropTypes.number,
  onChange:         PropTypes.func.isRequired,
  allowedLocations: PropTypes.arrayOf(
    PropTypes.shape({
      location_id:   PropTypes.number,
      location_name: PropTypes.string,
      location_type: PropTypes.string,
      location_code: PropTypes.string,
    })
  ),
  disabled:  PropTypes.bool,
  error:     PropTypes.string,
  className: PropTypes.string,
};
