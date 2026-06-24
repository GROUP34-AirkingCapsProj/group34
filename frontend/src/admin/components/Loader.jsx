import React from 'react';

/**
 * Loader — the one canonical loading mark for every admin surface.
 *
 *   <Loader />                          card / table section (default)
 *   <Loader variant="page" />           full route or dashboard
 *   <Loader variant="inline" />         next to a control or inside a dropdown
 *   <Loader label="Loading orders" />   custom caption (animated dots are added)
 *
 * Renders a crimson→gold sweep ring + a JetBrains-Mono caption. Styles live in
 * admin/styles/airking_ds.css (.ak-loader*), which AdminLayout imports globally.
 */
export default function Loader({
  variant = 'block',
  label = 'Loading',
  className = '',
  ...rest
}) {
  const cls = [
    'ak-loader',
    variant === 'page' && 'ak-loader--page',
    variant === 'inline' && 'ak-loader--inline',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={cls} role="status" aria-live="polite" {...rest}>
      <span className="ak-loader__ring" aria-hidden="true" />
      {label ? (
        <span className="ak-loader__label ak-loading-dots">{label}</span>
      ) : null}
    </div>
  );
}
