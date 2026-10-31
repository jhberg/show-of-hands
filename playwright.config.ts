import { defineConfig, devices } from '@playwright/test';

const phone = devices['Pixel 7'];
const desktop = { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } };
const members = /members\.spec/;

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
  // The member journeys change the member list, which the other journeys count, so they run last.
  projects: [
    { name: 'phone', use: phone, testIgnore: members },
    { name: 'desktop', use: desktop, testIgnore: members },
    { name: 'members-phone', use: phone, testMatch: members, dependencies: ['phone', 'desktop'] },
    { name: 'members-desktop', use: desktop, testMatch: members, dependencies: ['phone', 'desktop'] },
  ],
});
