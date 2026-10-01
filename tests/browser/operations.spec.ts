import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
test('operations trainer roster, restricted profile, attendance and responsive layout', async ({
    page,
}, info) => {
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`operations-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto('/operations');
    await page.getByLabel('Јазик').selectOption('en');
    await expect(
        page.getByRole('heading', {
            name: 'Daily operations',
            exact: true,
            level: 2,
        }),
    ).toBeVisible();
    await page
        .getByRole('link', { name: 'Roster', exact: true })
        .last()
        .click();
    await expect(
        page.getByRole('heading', { name: 'Roster', exact: true }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Quick profile', exact: true })
        .focus();
    await page.keyboard.press('Enter');
    await expect(
        page.getByText(
            'Membership and payment details require separate access. Ask authorized staff for follow-up.',
        ),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Close', exact: true }).focus();
    await page.keyboard.press('Enter');
    const attendance = page.getByRole('region', {
        name: 'Attendance',
        exact: true,
    });
    await attendance
        .getByRole('button', { name: 'Mark present', exact: true })
        .click();
    await expect(
        attendance.locator('article').filter({ hasText: 'Reserved Offline' }),
    ).toContainText('Present');
    await attendance.getByLabel('Find member for walk-in').fill('Walkin');
    await attendance
        .getByRole('button', { name: 'Search', exact: true })
        .click();
    await attendance
        .getByRole('button', { name: 'Add walk-in and check in' })
        .click();
    await expect(
        attendance.locator('article').filter({ hasText: 'Walkin Offline' }),
    ).toContainText('Present');
    for (const width of info.project.name === 'desktop' ? [1280, 768] : [360]) {
        await page.setViewportSize({ width, height: 900 });
        await page.screenshot({
            path: `.local/gt016/screenshots/gt016-${width}.png`,
            fullPage: true,
        });
        const overflow = await page.evaluate(() =>
            Array.from(document.querySelectorAll('main *'))
                .filter(
                    (el) => el.getBoundingClientRect().right > innerWidth + 1,
                )
                .map((el) => ({
                    tag: el.tagName,
                    class: el.className,
                    width: el.getBoundingClientRect().width,
                }))
                .slice(0, 12),
        );
        expect(overflow).toEqual([]);
        await page.screenshot({
            path: `.local/gt016/screenshots/gt016-${width}.png`,
            fullPage: true,
        });
    }
    await page.getByLabel('Language').selectOption('mk');
    await expect(
        page.getByRole('heading', { name: 'Список на учесници', exact: true }),
    ).toBeVisible();
});
test('operations administrator sees aggregate totals and a recoverable empty day', async ({
    page,
}, info) => {
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`operations-admin-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto('/operations');
    await page.getByLabel('Јазик').selectOption('en');
    await expect(
        page.getByRole('heading', {
            name: 'Daily operations',
            exact: true,
            level: 2,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', {
            name: 'Communication in the last 31 days',
        }),
    ).toBeVisible();
    await page.getByLabel('From', { exact: true }).fill('2020-01-01');
    await page.getByLabel('Until', { exact: true }).fill('2020-01-01');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(
        page.getByRole('link', { name: 'Roster', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Next', exact: true }),
    ).toBeDisabled();
});

test('two operators reconcile stale attendance rather than reporting false success', async ({
    page,
    browser,
}, info) => {
    const fixture = JSON.parse(
        readFileSync('.local/browser-operations-fixture.json', 'utf8'),
    )[info.project.name] as { ended: string };
    const second = await browser.newContext();
    const admin = await second.newPage();
    try {
        for (const [operator, email] of [
            [page, `operations-${info.project.name}@browser.example.test`],
            [
                admin,
                `operations-admin-${info.project.name}@browser.example.test`,
            ],
        ] as const) {
            await operator.goto('http://127.0.0.1:8011/login');
            await operator.getByLabel('Јазик').selectOption('en');
            await operator.getByLabel('Email').fill(email);
            await operator
                .getByLabel('Password', { exact: true })
                .fill('Browser-fixture-123!');
            await operator
                .getByRole('button', { name: 'Sign in', exact: true })
                .click();
            await expect(operator).not.toHaveURL(/login/);
            await operator.goto(
                `http://127.0.0.1:8011/operations/${fixture.ended}`,
            );
            await operator.getByLabel('Јазик').selectOption('en');
        }
        const firstRoster = page.getByRole('region', {
            name: 'Attendance',
            exact: true,
        });
        const secondRoster = admin.getByRole('region', {
            name: 'Attendance',
            exact: true,
        });
        await expect(
            firstRoster.getByRole('button', {
                name: 'Mark absent',
                exact: true,
            }),
        ).toBeVisible();
        await secondRoster
            .getByRole('button', { name: 'Mark no-show', exact: true })
            .click();
        await expect(
            secondRoster
                .locator('article')
                .filter({ hasText: 'Pending Fixture' }),
        ).toContainText('No-show');
        await firstRoster
            .getByRole('button', { name: 'Mark absent', exact: true })
            .click();
        await expect(firstRoster.getByRole('alert')).toBeVisible();
        await expect(
            firstRoster
                .locator('article')
                .filter({ hasText: 'Pending Fixture' }),
        ).toContainText('No-show');
        await expect(
            firstRoster.getByText('Attendance saved', { exact: true }),
        ).toHaveCount(0);
    } finally {
        await second.close();
    }
});
