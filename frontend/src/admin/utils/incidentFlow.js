import { reasonMeta } from './incidentReasons';

/**
 * The pure parts of the guided incident flow: which steps a reason needs, and
 * the two conversions between what the user types and what the API stores.
 *
 * These live outside the components because both conversions were live bugs —
 * a bare local timestamp the UTC server read as eight hours in the future, and
 * a pack quantity that wrote off a single piece — and a unit test is a cheaper
 * guard than remembering to re-check them by hand.
 */

export const STEP_KEYS = {
  WHAT: 'what',
  WHERE: 'where',
  OUTCOME: 'outcome',
  ITEMS: 'items',
  DETAILS: 'details',
};

/**
 * The two outcomes that create no `incident_items` on the backend, and so have
 * no items step to fill in.
 *
 * Mirrors IncidentService::INSPECTION_EXEMPT_REASONS. A zone mismatch says the
 * record is right and the item is merely in the wrong aisle, and `other` moves
 * no stock at all — neither quarantines anything, so neither has physical units
 * to enumerate.
 */
const OUTCOMES_WITHOUT_ITEMS = ['none', 'relocate'];

/**
 * The step sequence for a reason. Every flow shares three steps; the
 * outcome-specific one and the items one are what differ.
 */
export function stepsFor(reason) {
  const { outcome } = reasonMeta(reason);
  if (outcome === 'none') return [STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.DETAILS];
  if (OUTCOMES_WITHOUT_ITEMS.includes(outcome)) {
    return [STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.OUTCOME, STEP_KEYS.DETAILS];
  }
  return [STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.OUTCOME, STEP_KEYS.ITEMS, STEP_KEYS.DETAILS];
}

/**
 * `datetime-local` yields bare local wall-time ("2026-08-09T16:02") with no
 * zone. `new Date()` reads it in the device's zone; toISOString() makes it an
 * unambiguous UTC instant the server can compare from anywhere.
 */
export function toBackendInstant(localValue) {
  return localValue ? new Date(localValue).toISOString() : null;
}

/**
 * The ONE quantity that gets sent. Pack and loose-piece entry is convenience;
 * inventory, transfers and dispatch all store PIECES.
 */
export function totalPiecesFrom({ packs, loosePieces, quantity, piecesPerPackage }) {
  const per = Number(piecesPerPackage) || 0;
  const int = (v) => parseInt(v, 10) || 0;
  const total = per > 1
    ? int(packs) * per + int(loosePieces)
    : int(quantity);
  return Math.max(0, total);
}
