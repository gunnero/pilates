import { expect, test } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

test('report charts, period comparisons, private parsed exports and keyboard layouts', async ({
    page,
}, info) => {
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`reports-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto('/reports');
    await page.getByRole('banner').getByLabel('Јазик').selectOption('en');
    await expect(
        page.getByRole('region', { name: 'Report values' }).first(),
    ).toContainText('40.00');
    for (const granularity of ['weekly', 'monthly', 'daily']) {
        await page.getByLabel('Date buckets').selectOption(granularity);
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        await expect(
            page.getByRole('region', { name: 'Report values' }).first(),
        ).toContainText('40.00');
    }
    await page.getByRole('button', { name: 'All matching details' }).focus();
    await page.keyboard.press('Enter');
    await expect(
        page.getByRole('region', { name: 'Report values' }).first(),
    ).toContainText('+Синтетички план');
    mkdirSync('.local/gt017/browser', { recursive: true });
    for (const format of ['csv', 'xlsx']) {
        const pending = page.waitForEvent('download');
        await page
            .getByRole('button', {
                name: format === 'csv' ? 'Download CSV' : 'Download Excel',
            })
            .click();
        const file = await pending;
        const path = `.local/gt017/browser/${info.project.name}.${format}`;
        await file.saveAs(path);
        const parsed = JSON.parse(
            execFileSync(
                'python3',
                ['tests/Support/parse-report-export.py', path, format],
                { encoding: 'utf8' },
            ),
        );
        expect(parsed.rows).toBe(2);
        expect(parsed.net_minor).toBe('4000');
        expect(parsed.gross_minor).toBe('6000');
        expect(parsed.reversed_minor).toBe('2000');
    }
    await page
        .getByRole('combobox', { name: 'Report', exact: true })
        .selectOption('settlement');
    await expect(
        page.getByRole('region', { name: 'Report values' }).first(),
    ).toContainText('60.00');
    await expect(
        page.getByText(
            'Current snapshot, independent of the selected historical dates.',
        ),
    ).toBeVisible();
    await page
        .getByRole('combobox', { name: 'Report', exact: true })
        .selectOption('activity');
    await expect(
        page.getByText(
            'Current member population with participation in the selected period.',
        ),
    ).toBeVisible();
    await page
        .getByRole('combobox', { name: 'Report', exact: true })
        .selectOption('occupancy');
    await expect(
        page.getByRole('region', { name: 'Report values' }).first(),
    ).toBeVisible();
    for (const width of info.project.name === 'desktop' ? [1280, 768] : [360]) {
        await page.setViewportSize({ width, height: 900 });
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        ).toBe(true);
        await page.screenshot({
            path: `.local/gt017/browser/report-${width}.png`,
            fullPage: true,
        });
    }
    await page.getByRole('banner').getByLabel('Language').selectOption('mk');
    await expect(
        page.getByText('Извештаите ги читаат постојните записи.', {
            exact: false,
        }),
    ).toBeVisible();
});
test('report API bounds, foreign identifiers and private tenant isolation', async ({
    page,
}, info) => {
    const fixtures = JSON.parse(
        readFileSync('.local/browser-report-fixture.json', 'utf8'),
    );
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`reports-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    const options = {
        headers: {
            Referer: 'http://127.0.0.1:8011/',
            Accept: 'application/json',
        },
    };
    const own = fixtures[info.project.name].organization;
    const foreign =
        fixtures[info.project.name === 'desktop' ? 'mobile' : 'desktop']
            .organization;
    expect(
        (
            await page.request.get(
                `/api/v1/organizations/${foreign}/reports?report=revenue`,
                options,
            )
        ).status(),
    ).toBe(404);
    expect(
        (
            await page.request.get(
                `/api/v1/organizations/${own}/reports?report=revenue&from=2025-01-01&until=2026-10-01`,
                options,
            )
        ).status(),
    ).toBe(422);
    const response = await page.request.get(
        `/api/v1/organizations/${own}/reports?report=revenue`,
        options,
    );
    expect(response.headers()['cache-control']).toContain('no-store');
    expect((await response.json()).data.totals.net_minor).toBe('4000');
});

test('operational metrics, scoped ranking, custom comparison and failure recovery use live reports', async ({
    page,
}, info) => {
    const fixture = JSON.parse(
        readFileSync('.local/browser-report-fixture.json', 'utf8'),
    )[info.project.name];
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`reports-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto('/reports');
    await page.getByRole('banner').getByLabel('Јазик').selectOption('en');
    await page
        .getByRole('combobox', { name: 'Report', exact: true })
        .selectOption('attendance');
    await expect(page.getByRole('table').first()).toBeVisible();
    await page.getByLabel('From', { exact: true }).fill(fixture.ops_from);
    await page.getByLabel('Until', { exact: true }).fill(fixture.ops_until);
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    const totals = page.locator('.report-totals');
    await expect(totals).toContainText('50%');
    await expect(totals).toContainText('35.71%');
    await expect(totals).toContainText('14.29%');
    const scoped = page.getByRole('region', { name: 'Report values' });
    await expect(scoped.nth(1)).toContainText('Report Pilates');
    await expect(scoped.nth(2)).toContainText('Report Trainer');
    await page
        .getByRole('combobox', { name: 'Comparison', exact: true })
        .selectOption('custom');
    await expect(page.getByRole('status')).toContainText(
        'Choose both comparison dates',
    );
    await page
        .getByLabel('Comparison from', { exact: true })
        .fill(fixture.ops_from);
    await page
        .getByLabel('Comparison until', { exact: true })
        .fill(fixture.ops_until);
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(
        page.getByRole('region', { name: 'Report values' }).last(),
    ).toContainText('Attendance %');
    await page.route('**/reports?**', (route) => route.abort('failed'));
    await page.getByRole('button', { name: 'Refresh', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByRole('table')).toHaveCount(0);
    await page.unroute('**/reports?**');
    await page.getByRole('button', { name: 'Refresh', exact: true }).click();
    await expect(totals).toContainText('50%');
    await page
        .getByRole('combobox', { name: 'Report', exact: true })
        .selectOption('popularity');
    await expect(
        page.getByRole('region', { name: 'Report values' }).first(),
    ).toContainText('Report Pilates');
});

test('trainer with an explicit payment grant still cannot obtain financial reports or exports', async ({
    page,
}, info) => {
    const fixture = JSON.parse(
        readFileSync('.local/browser-report-fixture.json', 'utf8'),
    )[info.project.name];
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`report-trainer-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto('/reports');
    await page.getByRole('banner').getByLabel('Јазик').selectOption('en');
    await expect(page.getByRole('table').first()).toBeVisible();
    await expect(
        page.getByRole('option', { name: 'Net cash collections' }),
    ).toHaveCount(0);
    for (const suffix of ['', '&view=details', '&format=csv', '&format=xlsx']) {
        const response = await page.request.get(
            `/api/v1/organizations/${fixture.organization}/reports?report=revenue${suffix}`,
            {
                headers: {
                    Referer: 'http://127.0.0.1:8011/',
                    Accept: 'application/json',
                },
            },
        );
        expect(response.status()).toBe(403);
        expect(await response.text()).not.toContain('net_minor');
    }
});

test('isolated tenant browsers and delayed real responses cannot replace another report context', async ({
    page,
    browser,
}, info) => {
    const otherContext = await browser.newContext();
    const other = await otherContext.newPage();
    const alternate = info.project.name === 'desktop' ? 'mobile' : 'desktop';
    async function login(target: typeof page, viewport: string) {
        await target.goto('http://127.0.0.1:8011/login');
        await target.getByLabel('Јазик').selectOption('en');
        await target
            .getByLabel('Email')
            .fill(`reports-${viewport}@browser.example.test`);
        await target
            .getByLabel('Password', { exact: true })
            .fill('Browser-fixture-123!');
        await target
            .getByRole('button', { name: 'Sign in', exact: true })
            .click();
        await expect(target).not.toHaveURL(/login/);
        await target.goto('http://127.0.0.1:8011/reports');
        await target
            .getByRole('banner')
            .getByRole('combobox')
            .selectOption('en');
        await target
            .getByRole('button', { name: 'All matching details' })
            .click();
        await expect(target.getByRole('table').first()).toContainText(
            `+Синтетички план ${viewport}`,
        );
    }
    try {
        await login(page, info.project.name);
        await login(other, alternate);
        await expect(page.getByRole('table').first()).not.toContainText(
            `+Синтетички план ${alternate}`,
        );
        await expect(other.getByRole('table').first()).not.toContainText(
            `+Синтетички план ${info.project.name}`,
        );
        let release!: () => void;
        let captured!: () => void;
        const ready = new Promise<void>((resolve) => {
            captured = resolve;
        });
        const held = new Promise<void>((resolve) => {
            release = resolve;
        });
        await page.route('**/reports?**', async (route) => {
            if (!route.request().url().includes('report=settlement'))
                return route.continue();
            const response = await route.fetch();
            captured();
            await held;
            await route.fulfill({ response });
        });
        await page
            .getByRole('combobox', { name: 'Report', exact: true })
            .selectOption('settlement');
        await ready;
        await page
            .getByRole('combobox', { name: 'Report', exact: true })
            .selectOption('attendance');
        await expect(page.locator('.report-totals')).toContainText(
            'Attendance %',
        );
        release();
        await expect(page.locator('.report-totals')).not.toContainText(
            'Outstanding',
        );
        await expect(other.getByRole('table').first()).toContainText(
            `+Синтетички план ${alternate}`,
        );
        await page.unrouteAll({ behavior: 'wait' });
    } finally {
        await otherContext.close();
    }
});
