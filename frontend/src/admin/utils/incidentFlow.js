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
  DETAILS: 'details',
};

/**
 * The step sequence for a reason. Every flow shares three steps; only the
 * outcome-specific one differs, and `none` (the `other` reason) has none at
 * all because nothing about stock is being asserted.
 */
export function stepsFor(reason) {
  const { outcome } = reasonMeta(reason);
  return outcome === 'none'
    ? [STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.DETAILS]
    : [STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.OUTCOME, STEP_KEYS.DETAILS];
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
