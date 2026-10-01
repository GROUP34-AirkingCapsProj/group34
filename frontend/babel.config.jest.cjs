/**
 * Babel config used ONLY by Jest (wired up explicitly in jest.config.cjs).
 *
 * react-scripts used to supply this. It is deliberately NOT named
 * babel.config.cjs: @vitejs/plugin-react would auto-discover that name and
 * apply it to the browser build, which we don't want — Vite/esbuild handles
 * JSX for the app, and this file targets the Node version running the tests.
 */
module.exports = {
  presets: [
    ['@babel/preset-env', { targets: { node: 'current' } }],
    ['@babel/preset-react', { runtime: 'automatic' }],
  ],
};
