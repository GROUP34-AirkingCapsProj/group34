import { NAV_BUNDLES, bundleVisible } from './navBundles';

/**
 * Paths with no permission bundle yet that remain visible to ALL authenticated
 * roles this phase (spec §2.2 — warranty has no catalog feature yet, stays role-open).
 */
export const ROLE_OPEN_PATHS = new Set([
  '/admin/warranties',
  '/admin/warranty-claims',
]);

/**
 * Build sidebar sections from the canonical full menu, keeping only items whose
 * bundle the user can see (all required perms granted). Items with no matching
 * bundle are admin-only, EXCEPT role-open paths which are shown to everyone.
 */
export function buildSidebar(adminMenuSections, permissions, isAdmin) {
  const byPath = Object.fromEntries(NAV_BUNDLES.map((b) => [b.routePath, b]));
  return adminMenuSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => {
        const bundle = byPath[item.path];
        if (bundle) return bundleVisible(bundle, permissions);
        if (ROLE_OPEN_PATHS.has(item.path)) return true; // role-open, all roles
        return isAdmin; // admin-only pages (config, budget)
      }),
    }))
    .filter((section) => section.items.length > 0);
}
