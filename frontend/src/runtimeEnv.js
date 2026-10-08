/**
 * Reads a REACT_APP_* value exposed at runtime by the cra-process-env-shim
 * in vite.config.mjs. Use this instead of `process.env.REACT_APP_X` directly.
 *
 * Why: vite.config.mjs defines `process.env.NODE_ENV` via esbuild's `define`
 * so React's dead-code elimination can strip its dev-only branches in
 * production. That triggers a documented esbuild special case -- once
 * process.env.NODE_ENV is defined, esbuild treats every OTHER bare
 * `process.env.<key>` expression in the bundle as statically known and
 * replaces it with `({}).key`, i.e. permanently `undefined`, AT BUILD TIME.
 * This happens before the runtime shim script in index.html ever runs, so no
 * code is left in the shipped bundle that reads the values it injects.
 *
 * Confirmed by inspecting the compiled output directly: bare
 * `process.env.REACT_APP_API_URL` compiled to `{}.REACT_APP_API_URL`, and
 * every REACT_APP_* read in the app compiled the same way -- this was not a
 * one-off, it is every call site, in every environment, including on Render.
 * It explains why setting dashboard environment variables never had any
 * effect: the values were correctly injected into index.html, but the JS
 * bundle itself no longer contained an expression that read them.
 *
 * `window.process.env.X` is a different AST shape -- `process` is a property
 * access off `window`, not a bare free identifier -- so esbuild's pattern
 * match does not fire, and the expression survives into the bundle to be read
 * at runtime, exactly as the shim was designed for.
 *
 * In Jest, `window === global` under the jsdom test environment, so
 * `window.process` is the real Node `process` and this reads the same
 * `process.env` a test would set — verified directly, not assumed.
 */
export function readEnv(key) {
  if (typeof window !== 'undefined' && window.process && window.process.env) {
    return window.process.env[key];
  }
  // No non-browser build step uses this today, but getApiBaseUrl() already
  // has a `typeof window === 'undefined'` branch, so this mirrors it rather
  // than assuming window always exists.
  return typeof process !== 'undefined' ? process.env[key] : undefined;
}
