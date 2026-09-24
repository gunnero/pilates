import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
test('member inbox, promotion, reminders, expiry and private cash evidence', async ({
    page,
}, info) => {
    const fixtures = JSON.parse(
        readFileSync('.local/browser-notification-fixture.json', 'utf8'),
    ) as Record<string, { organization: string }>;
    const base = `/api/v1/organizations/${fixtures[info.project.name].organization}/notifications`;
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`notifications-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto('/notifications');
    await expect(
        page.getByRole('heading', { name: 'Известувања', exact: true }),
    ).toBeVisible();
    await page.getByLabel('Јазик').selectOption('en');
    await expect(
        page.getByRole('heading', { name: 'Notifications', exact: true }),
    ).toBeVisible();
    await expect(page.getByText('Unread: 8')).toBeVisible();
    const response = await page.request.get(base, {
        headers: {
            Accept: 'application/json',
            Origin: new URL(page.url()).origin,
        },
    });
    expect(response.ok()).toBe(true);
    const body = (await response.json()) as { data: { type: string }[] };
    for (const type of [
        'waiting.promoted',
        'reservation.confirmed',
        'reservation.cancelled',
        'session.reminder',
        'membership.expiring',
        'cash.recorded',
        'cash.reversed',
    ])
        expect(body.data.filter((n) => n.type === type)).toHaveLength(1);
    expect(JSON.stringify(body)).not.toMatch(
        /150000|amount_minor|balance_minor|agreed_price/,
    );
    await page
        .getByRole('button', { name: 'Mark read', exact: true })
        .first()
        .click();
    await expect(page.getByText('Unread: 7')).toBeVisible();
    await page.reload();
    await page.getByLabel('Јазик').selectOption('en');
    await expect(page.getByText('Unread: 7')).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${info.project.name}-notifications.png`,
        fullPage: true,
    });
    await page.getByRole('button', { name: 'Mark this page read' }).click();
    await expect(page.getByText('Unread: 0')).toBeVisible();
    await page.getByLabel('Unread only').check();
    await expect(page.getByText('No notifications.')).toBeVisible();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/login/);
});
