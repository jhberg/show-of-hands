import { defineConfig, devices } from '@playwright/test';

const phone = devices['Pixel 7'];
const desktop = { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } };
const members = /members\.spec/;
const settings = /settings\.spec/;
const polls = /admin-polls\.spec/;
const a11y = /a11y\.spec/;

// Runs inside the e2e Compose service, against the seeded e2e-app on port 8081.
// See compose.yaml: docker compose --profile e2e up --exit-code-from e2e e2e
export default defineConfig({
  testDir: 'tests/e2e',
  outputDir: 'tests/e2e/results',
  forbidOnly: !!process.env.CI,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8081',
    locale: 'de-DE',
    trace: 'retain-on-failure',
  },
  // The member journeys change the member list, which the other journeys count, so they run after them.
  projects: [
    { name: 'phone', use: phone, testIgnore: [members, settings, polls, a11y] },
    { name: 'desktop', use: desktop, testIgnore: [members, settings, polls, a11y] },
    { name: 'members-phone', use: phone, testMatch: members, dependencies: ['phone', 'desktop'] },
    { name: 'members-desktop', use: desktop, testMatch: members, dependencies: ['phone', 'desktop'] },
    // Saving the settings writes every value, so the two devices take turns, after everything else.
    { name: 'settings-phone', use: phone, testMatch: settings, dependencies: ['members-phone', 'members-desktop'] },
    { name: 'settings-desktop', use: desktop, testMatch: settings, dependencies: ['settings-phone'] },
    // New polls join the lists every other journey reads, so the poll admin journeys come last.
    { name: 'polls-phone', use: phone, testMatch: polls, dependencies: ['settings-desktop'] },
    { name: 'polls-desktop', use: desktop, testMatch: polls, dependencies: ['settings-desktop'] },
    // The accessibility pass visits every page with the data the journeys left, so it runs last.
    { name: 'a11y-phone', use: phone, testMatch: a11y, dependencies: ['polls-phone', 'polls-desktop'] },
    { name: 'a11y-desktop', use: desktop, testMatch: a11y, dependencies: ['a11y-phone'] },
  ],
});
