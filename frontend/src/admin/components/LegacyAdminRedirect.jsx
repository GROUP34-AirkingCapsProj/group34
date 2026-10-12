import { Navigate, useLocation, useParams } from 'react-router-dom';

/**
 * Translates a legacy /admin/* URL to its unprefixed form.
 *
 * The /admin prefix was removed from every route; this keeps old bookmarks, open
 * tabs, and — importantly — the deep links already written into notification
 * rows by AlertSettingsController and WarehouseNotificationService working.
 * Those rows are not back-filled, so this component is the only thing resolving
 * them.
 *
 * `replace` is deliberate: without it every legacy visit leaves an /admin entry
 * in history and the back button bounces through the redirect.
 */
export default function LegacyAdminRedirect() {
  const params = useParams();
  const { search, hash } = useLocation();

  // react-router v6 exposes the splat under the '*' key.
  const rest = params['*'] || '';

  // Bare /admin and /admin/ carry no destination, so fall back to login rather
  // than redirecting to '/', which would bounce again.
  const target = rest ? `/${rest}` : '/login';

  return <Navigate to={`${target}${search}${hash}`} replace />;
}
