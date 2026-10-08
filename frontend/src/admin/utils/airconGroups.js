/**
 * Presentation helpers for the `aircon_groups` key the PO / issuance / receiving
 * detail endpoints return (see AirconLineGrouper on the server).
 *
 * A split aircon is two separate products — an indoor unit and an outdoor unit —
 * that arrive as two independent document rows. The server tells us which rows
 * belong to the same aircon model; these helpers turn that into a render order
 * so the member rows can sit under one header without disturbing any document
 * that has no aircon tagging at all.
 */

/**
 * Lay a document's own detail rows out as segments for rendering.
 *
 * Returns an array of `{ key, group, rows }`. `group` is null for rows that are
 * not part of a tagged aircon model — those must render exactly as they did
 * before this feature. Segments come out in first-appearance order over
 * `details`, so an untagged document (or one the server returned no groups for)
 * yields a single null-group segment holding every row in its original order.
 *
 * @param {Array}  details  the document's detail rows, in display order
 * @param {Array}  groups   `aircon_groups` from the detail endpoint
 * @param {string} idKey    the row-id field: po_detail_id / issuance_detail_id /
 *                          receiving_detail_id. Groups are matched to rows by
 *                          this id, never by array index — the grouper builds
 *                          its own collection in its own order.
 */
export function layoutAirconGroups(details, groups, idKey) {
  const rows = Array.isArray(details) ? details : [];
  const groupList = Array.isArray(groups) ? groups : [];
  if (!rows.length) return [];

  // Only groups that actually name a model get a header; a null model id is the
  // grouper's way of saying "this line stands alone".
  const groupByRowId = new Map();
  groupList.forEach((g, gi) => {
    if (!g || g.aircon_model_id == null) return;
    (Array.isArray(g.lines) ? g.lines : []).forEach((line) => {
      const id = line?.[idKey];
      if (id != null && !groupByRowId.has(String(id))) {
        groupByRowId.set(String(id), { group: g, index: gi });
      }
    });
  });

  if (!groupByRowId.size) return [{ key: 'plain-0', group: null, rows }];

  const groupOf = (row) => {
    const id = row?.[idKey];
    return id == null ? null : groupByRowId.get(String(id)) || null;
  };

  const segments = [];
  const emitted = new Set();
  rows.forEach((row, i) => {
    const hit = groupOf(row);
    if (!hit) {
      const last = segments[segments.length - 1];
      if (last && last.group === null) last.rows.push(row);
      else segments.push({ key: `plain-${i}`, group: null, rows: [row] });
      return;
    }
    if (emitted.has(hit.index)) return; // already pulled in with its siblings
    emitted.add(hit.index);
    segments.push({
      key: `aircon-${hit.group.aircon_model_id}-${hit.index}`,
      group: hit.group,
      rows: rows.filter((r) => groupOf(r)?.index === hit.index),
    });
  });

  return segments;
}

/**
 * "TCL 2HP Split · 3 complete" — the header line for one grouped aircon model.
 *
 * KNOWN LIMITATION on the purchase order detail view, which bands its rows by
 * warehouse before grouping. purchase_order_details.location_id is per line, so
 * one model's indoor and outdoor rows CAN sit in different bands — in which
 * case each band renders its own header showing the same complete_count, and a
 * reader adding the headers up double-counts.
 *
 * The alternative — hoisting a model's rows out of their location band — was
 * rejected because it would misreport which warehouse the units were ordered
 * to, and that is the question the banding exists to answer. If this proves
 * confusing in practice, the fix is to compute the count per band rather than
 * to un-band the rows.
 */
export function airconGroupLabel(group) {
  const name = group?.model_name || 'Aircon model';
  const complete = Number(group?.complete_count || 0);
  return `${name} · ${complete} complete`;
}

/**
 * "2 indoor, 1 outdoor unpaired" — names the stranded units on a short
 * delivery. Returns '' when nothing is stranded, so callers can render nothing.
 * A short delivery is normal information, not an error.
 */
export function airconUnpairedLabel(unpaired) {
  if (!unpaired || typeof unpaired !== 'object') return '';
  const parts = Object.entries(unpaired)
    .filter(([, count]) => Number(count) > 0)
    .map(([role, count]) => `${Number(count)} ${role}`);
  return parts.length ? `${parts.join(', ')} unpaired` : '';
}
