import { defineConfig } from 'cypress';

/**
 * Cypress runs against a real Joomla with J2Commerce and the plugin installed, seeded
 * with tools/seed-dev-site.php: the tools are only worth something if the real shop
 * answers them.
 *
 * The site comes from `cypress.env.json`, which is git-ignored. Copy
 * `cypress.env.json.dist` and fill it in.
 */
export default defineConfig({
  e2e: {
    // Overridden by `baseUrl` in cypress.env.json.
    baseUrl: 'http://localhost/plg_system_webmcp/joomla',
    supportFile: 'tests/cypress/support/e2e.js',
    specPattern: 'tests/cypress/e2e/**/*.cy.js',
    video: false,
    screenshotOnRunFailure: true,
    screenshotsFolder: 'tests/cypress/screenshots',
    downloadsFolder: 'tests/cypress/downloads',

    setupNodeEvents(on, config) {
      if (config.env.baseUrl) {
        config.baseUrl = config.env.baseUrl;
      }

      return config;
    },
  },
});
