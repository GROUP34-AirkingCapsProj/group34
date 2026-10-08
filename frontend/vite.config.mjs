import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

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

  // Resolved in the sw-build-id plugin's configResolved, read in its closeBundle.
  let swOutDir;

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

      /**
       * Stamps a unique build id into public/service-worker.js.
       *
       * CACHE_NAME used to be a hand-written 'v2' that had to be bumped by hand
       * whenever the cached shell changed. It never was, so every returning
       * visitor kept serving the index.html from whichever deploy they first
       * visited -- and because the cra-process-env-shim above writes the app's
       * configuration INTO index.html, a stale shell means a stale API URL. A
       * browser holding a shell from before the production API was configured
       * sends every request to 127.0.0.1.
       *
       * Files in public/ are copied verbatim and never transformed, so the
       * substitution happens on the emitted copy after the bundle is written.
       * Dev is unaffected: the service worker is only registered when
       * NODE_ENV === 'production' (see src/serviceWorkerRegistration.js), so the
       * un-substituted placeholder is never reached there.
       */
      {
        name: 'sw-build-id',
        apply: 'build',
        configResolved(resolved) {
          // A closure variable, not `this`: Vite does not share a `this` between
          // plugin hooks, so anything stashed on it in configResolved is gone by
          // closeBundle.
          swOutDir = resolve(resolved.root, resolved.build.outDir);
        },
        closeBundle() {
          if (!swOutDir) return;
          const file = resolve(swOutDir, 'service-worker.js');
          if (!existsSync(file)) return;

          const source = readFileSync(file, 'utf8');
          if (!source.includes('__BUILD_ID__')) return;

          // Time-based rather than content-hashed: the point is "this is a
          // different deploy", and the shell's content is index.html, which the
          // worker does not see at build time.
          const buildId = Date.now().toString(36);
          writeFileSync(file, source.replaceAll('__BUILD_ID__', buildId), 'utf8');
          // eslint-disable-next-line no-console
          console.log(`sw-build-id: cache busted as airking-pwa-shell-${buildId}`);
        },
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
