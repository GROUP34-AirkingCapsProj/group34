/**
 * Standalone Jest config.
 *
 * react-scripts used to generate all of this internally; once Vite owns the dev
 * server and the build, Jest needs it spelled out. The goal is strict parity with
 * the previous `react-scripts test` behaviour — the 59 existing test files and the
 * 445 assertions in them are not modified.
 */
module.exports = {
  testEnvironment: 'jsdom',

  // CRA injected react-app-polyfill/jsdom, whose only job was to require
  // 'whatwg-fetch'. admin/services/api.js calls fetch() in 5 places, so a
  // component test that triggers a request needs fetch to exist.
  setupFiles: ['whatwg-fetch'],

  // Was CRA's default; src/setupTests.js pulls in @testing-library/jest-dom.
  setupFilesAfterEnv: ['<rootDir>/src/setupTests.js'],

  // Point babel-jest at the Jest-only babel config so @vitejs/plugin-react
  // never picks it up for the browser build.
  transform: {
    '^.+\\.[jt]sx?$': ['babel-jest', { configFile: './babel.config.jest.cjs' }],
  },

  moduleFileExtensions: ['js', 'jsx', 'json', 'node'],

  // Both patterns copied from CRA's createJestConfig, minus the TS extensions
  // (this project has no TypeScript).
  testMatch: [
    '<rootDir>/src/**/__tests__/**/*.{js,jsx}',
    '<rootDir>/src/**/*.{spec,test}.{js,jsx}',
  ],

  moduleNameMapper: {
    // --- carried over verbatim from the old package.json "jest" block ---
    // react-router v7 ships export maps Jest's resolver picks the wrong branch of.
    '^react-router-dom$': '<rootDir>/node_modules/react-router-dom/dist/index.js',
    '^react-router/dom$': '<rootDir>/node_modules/react-router/dist/development/dom-export.js',
    '^react-router$': '<rootDir>/node_modules/react-router/dist/development/index.js',

    // --- replacing what react-scripts handled implicitly ---
    '\\.(css|less|sass|scss)$': '<rootDir>/test/styleMock.cjs',
    '\\.(png|jpe?g|gif|svg|webp|avif|mp3|wav|woff2?|ttf|eot)$': '<rootDir>/test/fileMock.cjs',
  },

  // CRA set this, and the existing tests were written against it: mock state and
  // implementations are reset before every test. Leaving it false would silently
  // change behaviour for the 256 jest.fn() call sites.
  resetMocks: true,
};
