import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    workers: 1,
    reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8011', trace: 'off' },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        {
            name: 'mobile',
            use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' },
        },
    ],
    webServer: {
        command:
            'scripts/prepare-browser && php artisan serve --env=e2e --host=127.0.0.1 --port=8011',
        url: 'http://127.0.0.1:8011/up',
        reuseExistingServer: false,
        timeout: 30000,
    },
});
