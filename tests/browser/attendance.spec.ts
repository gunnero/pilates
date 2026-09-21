import { expect, test } from '@playwright/test';

test('trainer today, offline attendance and walk-in roster', async ({
    page,
}, info) => {
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`attendance-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: /My sessions/ }).click();
    await expect(
        page.getByRole('heading', { name: "Today's sessions" }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Open session' }).click();
    const roster = page.getByRole('region', {
        name: 'Attendance',
        exact: true,
    });
    await roster.getByRole('button', { name: 'Mark present' }).click();
    await expect(
        roster.locator('article').filter({ hasText: 'Reserved Offline' }),
    ).toContainText('Present');
    await roster
        .getByRole('textbox', { name: 'Find member for walk-in' })
        .fill('Walkin');
    await roster.getByRole('button', { name: 'Search', exact: true }).click();
    await roster
        .getByRole('button', { name: 'Add walk-in and check in' })
        .click();
    await expect(
        roster.locator('article').filter({ hasText: 'Walkin Offline' }),
    ).toContainText('Present');
    await expect(
        page.getByText('Reserved: 2 · Available: 10 · Waiting: 0'),
    ).toBeVisible();
    await roster.getByRole('button', { name: 'Show check-in QR' }).click();
    await expect(roster.locator('svg')).toBeVisible();
    // Remove the live token before retaining a screenshot.
    await roster.getByRole('button', { name: 'Hide QR' }).click();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${info.project.name}-attendance.png`,
        fullPage: true,
    });
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/login/);
});
