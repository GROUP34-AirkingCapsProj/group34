import { NAV_BUNDLES } from './navBundles';

describe('NAV_BUNDLES', () => {
  test('does not contain the retired Approval Queue page', () => {
    const paths = NAV_BUNDLES.map((b) => b.routePath);
    expect(paths).not.toContain('/admin/approval-queue');
    expect(NAV_BUNDLES.some((b) => b.name === 'Approval Queue')).toBe(false);
  });

  test('still exposes the Purchase Orders page', () => {
    expect(NAV_BUNDLES.some((b) => b.routePath === '/admin/draft-po-creator')).toBe(true);
  });
});
