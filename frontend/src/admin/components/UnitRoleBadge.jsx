import React from 'react';

/**
 * Marks which half of a split aircon a product row is.
 *
 * Why this exists at all: an indoor unit and its outdoor partner share a
 * brand, a capacity, a variant and usually a near-identical name. On any list
 * — picking, receiving, issuing, transferring — they are indistinguishable by
 * name, and the role is the only thing separating the right carton from the
 * wrong one.
 *
 * Renders nothing for a 'complete' unit, for a non-appliance, or for a
 * product whose role was never set. That is deliberate: 'complete' is what
 * every appliance is until it is split, so badging it would put a badge on
 * all 208 rows and bury the signal in noise. Only a half is marked.
 *
 * Colours match the scan screens (indigo indoor, teal outdoor) so the same
 * unit reads the same way everywhere it appears.
 */
export default function UnitRoleBadge({ role, className = '', style = {} }) {
  if (role !== 'indoor' && role !== 'outdoor') return null;

  return (
    <span
      className={className}
      style={{
        display: 'inline-block',
        marginRight: 6,
        padding: '1px 6px',
        borderRadius: 4,
        fontSize: 10,
        fontWeight: 800,
        letterSpacing: '0.4px',
        color: '#fff',
        background: role === 'indoor' ? '#4338ca' : '#0f766e',
        ...style,
      }}
    >
      {role === 'indoor' ? 'INDOOR' : 'OUTDOOR'}
    </span>
  );
}
