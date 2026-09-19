import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
test('generated session, manual occurrence, override and cancellation', async ({
    page,
}, info) => {
    const fixture = JSON.parse(
        readFileSync('.local/browser-session-fixture.json', 'utf8'),
    ) as Record<string, { date: string }>;
    const date = fixture[info.project.name].date;
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`sessions-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Schedule', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: '10:00–11:00 · Session Pilates' }),
    ).toBeVisible();
    await page
        .getByRole('link', {
            name: `Open session ${date} 10:00 Session Pilates`,
        })
        .click();
    await expect(page.getByText(/From weekly schedule/)).toBeVisible();
    await page.getByRole('link', { name: 'Back to agenda' }).click();
    await page
        .getByRole('button', { name: 'New session', exact: true })
        .click();
    await page
        .getByRole('combobox', { name: 'Group', exact: true })
        .first()
        .selectOption({ label: 'Session Pilates' });
    await page.getByLabel('Session date').fill(date);
    await page.getByLabel('Start time', { exact: true }).fill('20:00');
    await page.getByLabel('End time', { exact: true }).fill('21:00');
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: '20:00–21:00 · Session Pilates' }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Edit only this occurrence' })
        .click();
    await page.getByLabel('Start time', { exact: true }).fill('21:00');
    await page.getByLabel('End time', { exact: true }).fill('22:00');
    await page.getByLabel('Capacity (1–100)').fill('10');
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${info.project.name}-session-edit.png`,
        fullPage: true,
    });
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: '21:00–22:00 · Session Pilates' }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Cancel occurrence', exact: true })
        .click();
    await page.getByLabel('Reason (optional)').fill('Fixture cancellation');
    await page.getByRole('button', { name: 'Confirm cancellation' }).click();
    await expect(
        page.getByRole('strong').filter({ hasText: /^Cancelled$/ }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Edit only this occurrence' }),
    ).toHaveCount(0);
    await page.screenshot({
        path: `test-results/${info.project.name}-session-cancelled.png`,
        fullPage: true,
    });
    await page.getByRole('link', { name: 'Back to agenda' }).click();
    await expect(
        page.getByRole('heading', { name: '10:00–11:00 · Session Pilates' }),
    ).toBeVisible();
    await expect(
        page.getByRole('strong').filter({ hasText: /^Cancelled$/ }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    expect(errors).toEqual([]);
});
