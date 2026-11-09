import { defineConfig, devices } from '@playwright/test'

// UI regression tests for the standalone iPhone player (/player.html).
// Every /api request is mocked in e2e/fixtures/player.ts; nothing reaches a real server.
const PORT = 4180

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
    : [['list']],
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    serviceWorkers: 'block',
  },
  projects: [
    // iPhone 13: 390×844 viewport, device pixel ratio 3, touch, iPhone user agent.
    { name: 'iphone-chromium', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
    { name: 'iphone-webkit', use: { ...devices['iPhone 13'], browserName: 'webkit' } },
  ],
  webServer: {
    command: `npx vite --host 127.0.0.1 --port ${PORT} --strictPort`,
    url: `http://127.0.0.1:${PORT}/player.html`,
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
})
