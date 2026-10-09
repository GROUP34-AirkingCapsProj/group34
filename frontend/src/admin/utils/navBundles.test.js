import { NAV_BUNDLES, bundleVisible } from './navBundles';

describe('NAV_BUNDLES', () => {
  test('reports hub is accessible with either permission, without granting the other tab', () => {
    const bundle = NAV_BUNDLES.find(b => b.routePath === '/admin/reports');
    expect(bundleVisible(bundle, { 'analytics_dashboard.read': true })).toBe(true);
    expect(bundleVisible(bundle, { 'report_viewing.read': true })).toBe(true);
    expect(bundleVisible(bundle, {})).toBe(false);
    expect(bundleVisible(bundle, null)).toBe(false);
  });
  test('does not contain the retired Approval Queue page', () => {
    const paths = NAV_BUNDLES.map((b) => b.routePath);
    expect(paths).not.toContain('/admin/approval-queue');
    expect(NAV_BUNDLES.some((b) => b.name === 'Approval Queue')).toBe(false);
  });

  test('still exposes the Purchase Orders page', () => {
    expect(NAV_BUNDLES.some((b) => b.routePath === '/admin/draft-po-creator')).toBe(true);
  });
});
