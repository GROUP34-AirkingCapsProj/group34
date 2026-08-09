import { stepsFor, toBackendInstant, totalPiecesFrom, STEP_KEYS } from './incidentFlow';

describe('stepsFor', () => {
  it('gives a stock-affecting reason four steps, outcome step third', () => {
    expect(stepsFor('theft')).toEqual([
      STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.OUTCOME, STEP_KEYS.DETAILS,
    ]);
  });

  it('skips the outcome step for `other`, which moves no stock', () => {
    expect(stepsFor('other')).toEqual([
      STEP_KEYS.WHAT, STEP_KEYS.WHERE, STEP_KEYS.DETAILS,
    ]);
  });

  it('treats an unrecognised reason as `other` rather than throwing', () => {
    expect(stepsFor('not_a_real_reason')).toHaveLength(3);
  });
});

describe('toBackendInstant', () => {
  // A bare `datetime-local` value has no zone. Sent verbatim it made the UTC
  // server read a Manila "now" as eight hours ahead, so before_or_equal:now
  // rejected every filing that carried a timestamp.
  it('turns a bare local wall-time into a UTC instant', () => {
    const out = toBackendInstant('2026-08-09T16:02');
    expect(out).toBe(new Date('2026-08-09T16:02').toISOString());
    expect(out).toMatch(/Z$/);
  });

  it('returns null for an empty value rather than an Invalid Date', () => {
    expect(toBackendInstant('')).toBeNull();
    expect(toBackendInstant(null)).toBeNull();
  });
});

describe('totalPiecesFrom', () => {
  // Stock is stored in PIECES everywhere. Reporting "1" for a damaged box used
  // to write off a single piece.
  it('converts packs plus loose pieces to pieces', () => {
    expect(totalPiecesFrom({ packs: 2, loosePieces: 5, quantity: 1, piecesPerPackage: 10 }))
      .toBe(25);
  });

  it('uses the plain quantity when the product has no pack size', () => {
    expect(totalPiecesFrom({ packs: 9, loosePieces: 9, quantity: 3, piecesPerPackage: 1 }))
      .toBe(3);
  });

  it('reads empty and non-numeric inputs as zero, never NaN', () => {
    expect(totalPiecesFrom({ packs: '', loosePieces: '', quantity: '', piecesPerPackage: 10 }))
      .toBe(0);
    expect(totalPiecesFrom({ packs: 'x', loosePieces: '', quantity: '', piecesPerPackage: 0 }))
      .toBe(0);
  });

  it('never returns a negative total', () => {
    expect(totalPiecesFrom({ packs: 0, loosePieces: 0, quantity: -5, piecesPerPackage: 1 }))
      .toBe(0);
  });
});
