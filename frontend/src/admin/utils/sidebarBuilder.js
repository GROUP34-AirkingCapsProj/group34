import { NAV_BUNDLES, bundleVisible } from './navBundles';
import { canAccessRoute, getRoleKey, ROLES } from './roles';

/** Pages with no permission bundle that remain visible to all authenticated roles.
 *  Self-service account settings must be reachable by every user (own account). */
export const ROLE_OPEN_PATHS = new Set(['/account']);

/**
 * Build sidebar sections: keep a page if its bundle is visible (perm granted),
 * else if it's role-open, else fall back to the role list (canAccessRoute).
 * adminOnly items are admin-only regardless.
 */
export function buildSidebar(menuSections, permissions, user) {
  const byPath = Object.fromEntries(NAV_BUNDLES.map((b) => [b.routePath, b]));
  const isAdmin = getRoleKey(user) === ROLES.ADMIN;
  return menuSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => {
        if (item.adminOnly && !isAdmin) return false;
        const bundle = byPath[item.path];
        if (bundle) return bundleVisible(bundle, permissions);
        if (ROLE_OPEN_PATHS.has(item.path)) return true;
        return canAccessRoute(user, item.path);
      }),
    }))
    .filter((section) => section.items.length > 0);
}
