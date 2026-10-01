import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Vite replaces react-scripts for the dev server and the production build.
 * Jest stays as the test runner (see jest.config.cjs) so the existing 59 test
 * files keep working untouched.
 *
 * CRA compatibility is deliberate here: the app reads its configuration through
 * `process.env.REACT_APP_*` in five files (admin/services/api.js, admin/utils/echo.js,
 * admin/utils/notifySound.js, supabase/client.js, serviceWorkerRegistration.js).
 * Instead of rewriting those to `import.meta.env`, we inject the same names, which
 * also means every existing `.env*` file stays valid in CRA format.
 */
export default defineConfig(({ mode }) => {
  // Only REACT_APP_*, matching what CRA exposed to the bundle. Secrets without
  // that prefix (there are none today) stay server-side, as before.
  const env = loadEnv(mode, process.cwd(), 'REACT_APP_');

  return {
    plugins: [
      // Don't let this pick up babel.config.jest.cjs — that file is Jest's alone.
      react({ babel: { babelrc: false, configFile: false } }),

      /**
       * Supplies `process.env` to the browser at runtime.
       *
       * Why a runtime shim instead of `define`: Vite's `define` does not replace
       * the bare `process.env` member expression during dev, so the five consumer
       * modules would hit "process is not defined" in the browser. And the obvious
       * alternative — rewriting them to `import.meta.env` — breaks Jest, which
       * cannot parse `import.meta`. Injecting a classic script keeps one spelling
       * (`process.env.X`) working in dev, in the production bundle, and in Jest.
       *
       * Only REACT_APP_*-prefixed values are injected, so this exposes exactly
       * what CRA exposed. Note this lands in the HTML in clear text — same as CRA,
       * where these were inlined into the bundle. Never put a secret in one.
       */
      {
        name: 'cra-process-env-shim',
        transformIndexHtml: () => [
          {
            tag: 'script',
            injectTo: 'head-prepend',
            children: `window.process=window.process||{};window.process.env=Object.assign({},window.process.env,${JSON.stringify(
              {
                ...env,
                PUBLIC_URL: '',
                NODE_ENV: mode === 'development' ? 'development' : 'production',
              },
            )});`,
          },
        ],
      },
    ],

    // Keeps the CRA prefix working for any future `import.meta.env` reads too.
    envPrefix: 'REACT_APP_',

    /**
     * NODE_ENV is defined statically (not left to the runtime shim above) because
     * React and several libraries gate their development-only code on
     * `process.env.NODE_ENV === 'production'`. Replacing it at build time is what
     * lets the minifier strip those branches; a runtime lookup cannot be
     * dead-code-eliminated and would ship React's dev build to production.
     *
     * Everything else (the REACT_APP_* values) is read at runtime from the shim,
     * which also means an unset var — several are commented out in .env — simply
     * evaluates to undefined, exactly as the call sites expect.
     */
    define: {
      'process.env.NODE_ENV': JSON.stringify(
        mode === 'development' ? 'development' : 'production',
      ),
    },

    server: {
      // Was HOST=0.0.0.0 in .env.development, so phones on the same Wi-Fi can connect.
      host: true,
      // Keep port 3000 — bookmarks, the CORS origin and the docs all assume it.
      port: 3000,
      // Replaces the "proxy" field package.json used to carry for CRA.
      proxy: {
        '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
      },
    },

    build: {
      // CRA wrote to build/; DEPLOYMENT.md and the serve:build script expect that path.
      outDir: 'build',
      sourcemap: false,
    },
  };
});
