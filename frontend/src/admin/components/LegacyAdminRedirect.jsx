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

  // react-router v7 exposes the splat under the '*' key.
  const rest = params['*'] || '';

  // Bare /admin and /admin/ carry no destination, so fall back to login rather
  // than redirecting to '/', which would bounce again.
  if (!rest) {
    return <Navigate to="/login" replace />;
  }

  // A splat segment can itself start with '/' (e.g. the legacy URL had a
  // doubled slash, "/admin//dashboard" -> rest === "/dashboard"). Naively
  // prepending another '/' then yields "//dashboard", a protocol-relative
  // URL that react-router's <Navigate> refuses to follow ("External
  // navigation is not allowed") and crashes with no error boundary to catch
  // it. Collapse any run of slashes down to one so the result always stays
  // a same-origin absolute path. Do not "simplify" this back to a template
  // string — that is exactly the bug this component exists to avoid.
  // react-router has already percent-decoded the splat, so a path segment
  // such as "report%23summary" arrives here as the literal string
  // "report#summary". react-router's object `to` form flatly refuses a raw
  // '#' or '?' inside `pathname` ("Cannot include a '#' character in a
  // manually specified `to.pathname` field") because those are the
  // search/hash delimiters. Re-encode them so they survive as ordinary path
  // characters instead of being rejected or, worse, misread as a delimiter.
  const pathname = `/${rest}`
    .replace(/\/{2,}/g, '/')
    .replace(/#/g, '%23')
    .replace(/\?/g, '%3F');

  // Pass the destination as the object form, not a single concatenated
  // string. <Navigate to="..."> re-parses a string target for '?' and '#',
  // so a literal '#' or '?' that is part of a path segment would be sliced
  // out of the pathname and misread as a search/hash separator. The object
  // form assigns pathname/search/hash directly with no re-parsing, so the
  // re-encoded characters above stay put in the path. Do not "simplify"
  // this back into a template string — that is exactly the bug this
  // component exists to avoid.
  return <Navigate to={{ pathname, search, hash }} replace />;
}
