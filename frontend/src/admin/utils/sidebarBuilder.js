import { NAV_BUNDLES, bundleVisible } from './navBundles';

/**
 * Build sidebar sections from the canonical full menu, keeping only items whose
 * bundle the user can see (all required perms granted). Items with no matching
 * bundle are treated as admin-only: shown only when isAdmin is true.
 */
export function buildSidebar(adminMenuSections, permissions, isAdmin) {
  const byPath = Object.fromEntries(NAV_BUNDLES.map((b) => [b.routePath, b]));
  return adminMenuSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => {
        const bundle = byPath[item.path];
        if (!bundle) return isAdmin; // admin-only pages (config, budget, warranty)
        return bundleVisible(bundle, permissions);
      }),
    }))
    .filter((section) => section.items.length > 0);
}
