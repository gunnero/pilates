import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
test('offline reservation, FIFO waiting, permanent place and one occurrence release', async ({
    page,
}, info) => {
    const fixtures = JSON.parse(
        readFileSync('.local/browser-booking-fixture.json', 'utf8'),
    ) as Record<string, { date: string; session: string; group: string }>;
    const f = fixtures[info.project.name];
    page.on('dialog', (d) => void d.accept());
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`bookings-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto(`/schedule/${f.session}`);
    await page
        .getByRole('combobox', { name: /Јазик|Language/ })
        .selectOption('en');
    await expect(
        page.getByRole('heading', { name: '10:00–11:00 · Booking Pilates' }),
    ).toBeVisible();
    const search = async (name: string) => {
        await page
            .getByRole('textbox', { name: 'Search', exact: true })
            .fill(name);
        await page.getByRole('button', { name: 'Search', exact: true }).click();
    };
    await search('Offline');
    await page
        .getByRole('button', { name: 'Reserve a place / join waiting list' })
        .click();
    await expect(page.getByRole('status')).toHaveText('The place is reserved.');
    await expect(
        page.getByText('Reserved: 1 · Available: 0 · Waiting: 0'),
    ).toBeVisible();
    await search('Ana');
    await expect(
        page
            .locator('.record-row')
            .filter({ hasText: 'Ana Fixture' })
            .getByRole('button', {
                name: 'Reserve a place / join waiting list',
            }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Reserve a place / join waiting list' })
        .click();
    await expect(page.getByRole('status')).toHaveText(
        'Added to the waiting list.',
    );
    await page
        .locator('.record-row')
        .filter({ hasText: 'Offline Fixture · Active' })
        .getByRole('button', { name: 'Cancel', exact: true })
        .click();
    await expect(
        page.locator('.record-row').filter({ hasText: 'Ana Fixture · Active' }),
    ).toBeVisible();
    await expect(
        page.getByText('Reserved: 1 · Available: 0 · Waiting: 0'),
    ).toBeVisible();
    await page
        .locator('.record-row')
        .filter({ hasText: 'Ana Fixture · Active' })
        .getByRole('button', { name: 'Cancel', exact: true })
        .click();
    await expect(
        page.getByText('Reserved: 0 · Available: 1 · Waiting: 0'),
    ).toBeVisible();
    await page.goto(`/groups/${f.group}`);
    await page
        .getByRole('combobox', { name: /Јазик|Language/ })
        .selectOption('en');
    await expect(
        page.getByRole('heading', { name: 'Permanent places', exact: true }),
    ).toBeVisible();
    await search('Elena');
    await page.getByLabel('Effective from').fill(f.date);
    await page
        .getByRole('button', { name: 'Add permanent place', exact: true })
        .click();
    await expect(page.getByRole('status')).toHaveText('Permanent place saved.');
    await page.goto(`/schedule/${f.session}`);
    await page
        .getByRole('combobox', { name: /Јазик|Language/ })
        .selectOption('en');
    await page
        .getByRole('button', { name: 'Release only this occurrence' })
        .click();
    await expect(
        page.getByText('Reserved: 0 · Available: 1 · Waiting: 0'),
    ).toBeVisible();
    await page.goto(`/groups/${f.group}`);
    await page
        .getByRole('combobox', { name: /Јазик|Language/ })
        .selectOption('en');
    await expect(
        page.getByText('Elena Fixture', { exact: true }),
    ).toBeVisible();
    await page
        .locator('article')
        .filter({ hasText: 'Elena Fixture' })
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(page.getByRole('status')).toHaveText('Permanent place saved.');
    await page.goto(`/schedule/${f.session}`);
    await page
        .getByRole('combobox', { name: /Јазик|Language/ })
        .selectOption('en');
    await expect(
        page.getByText('Reserved: 0 · Available: 1 · Waiting: 0'),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${info.project.name}-reservations.png`,
        fullPage: true,
    });
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/login/);
});
