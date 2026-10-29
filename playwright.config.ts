import { defineConfig, devices } from '@playwright/test';

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
  projects: [
    { name: 'phone', use: { ...devices['Pixel 7'] } },
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
  ],
});
