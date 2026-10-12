import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

async function request(
    page: Page,
    path: string,
    method: string,
    body?: unknown,
) {
    return page.evaluate(
        async ({ path, method, body }) => {
            const csrf = document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const r = await fetch(path, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
                },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
            return { status: r.status, body: await r.json() };
        },
        { path, method, body },
    );
}
test('communication announcements, session changes, preferences, read state and tenant denial', async ({
    page,
    browser,
}, info) => {
    const fixtures = JSON.parse(
        readFileSync('.local/browser-notification-fixture.json', 'utf8'),
    );
    const name = `communication-${info.project.name}`;
    const f = fixtures[name];
    const base = `/api/v1/organizations/${f.organization}`;
    const process = () =>
        execFileSync(
            'php',
            ['tests/browser/process-communication.php', f.organization],
            { encoding: 'utf8' },
        );
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`notifications-staff-${name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto('/settings');
    await page.getByRole('banner').getByLabel('Јазик').selectOption('en');
    const announcements = page.getByRole('region', {
        name: 'Studio announcements',
        exact: true,
    });
    await announcements.getByLabel('Title (MK)').fill('Ново известување');
    await announcements.getByLabel('Title (EN)').fill('Studio update');
    await announcements
        .getByLabel('Message (MK)')
        .fill('Проверете го распоредот.');
    await announcements
        .getByLabel('Message (EN)')
        .fill('Please check your schedule.');
    await announcements
        .getByRole('button', { name: 'Queue announcement' })
        .click();
    await expect(
        announcements.getByText('Studio update', { exact: true }),
    ).toBeVisible();
    process();
    const current = (
        await request(page, `${base}/sessions/${f.session}`, 'GET')
    ).body.data;
    let start = new Date(new Date(current.starts_at).getTime() + 3 * 3600000);
    let end = new Date(start.getTime() + 3600000);
    const format = (d: Date) =>
        new Intl.DateTimeFormat('sv-SE', {
            timeZone: current.timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .format(d)
            .split(' ');
    // Sessions use a single studio-local date. Keep this change valid near midnight.
    if (format(start)[0] !== format(end)[0]) {
        start = new Date(start.getTime() + 12 * 3600000);
        end = new Date(start.getTime() + 3600000);
    }
    expect(format(end)[0]).toBe(format(start)[0]);
    const [date, time] = format(start);
    expect(
        (
            await request(page, `${base}/sessions/${f.session}`, 'PATCH', {
                location_id: current.location.id,
                trainer_id: current.trainer.id,
                capacity: current.capacity,
                local_date: date,
                start_time: time,
                end_time: format(end)[1],
            })
        ).status,
    ).toBe(200);
    process();
    expect(
        (
            await request(
                page,
                `${base}/sessions/${f.session}/cancel`,
                'POST',
                {},
            )
        ).status,
    ).toBe(200);
    process();
    const context = await browser.newContext({
        baseURL: 'http://127.0.0.1:8011',
        viewport: page.viewportSize() ?? undefined,
    });
    const member = await context.newPage();
    await member.goto('/login');
    await member.getByLabel('Јазик').selectOption('en');
    await member
        .getByLabel('Email')
        .fill(`notifications-${name}@browser.example.test`);
    await member
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await member.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(member).not.toHaveURL(/login/);
    await member.goto('/notifications');
    await member.getByLabel('Јазик').selectOption('en');
    await expect(
        member.getByRole('heading', { name: /^Studio update/ }),
    ).toBeVisible();
    const notices = (await request(member, `${base}/notifications`, 'GET')).body
        .data;
    for (const type of [
        'studio.announcement',
        'session.changed',
        'session.cancelled',
        'waiting.promoted',
        'membership.expiring',
    ])
        expect(notices.some((n: { type: string }) => n.type === type)).toBe(
            true,
        );
    await member.getByLabel('Push notifications').click();
    await expect(member.getByLabel('Push notifications')).not.toBeChecked();
    await member.getByRole('button', { name: 'Mark all as read' }).click();
    await expect(member.getByText('Unread: 0')).toBeVisible();
    expect(
        (
            await request(member, `${base}/announcements`, 'POST', {
                request_id: crypto.randomUUID(),
                title_mk: 'Тест',
                title_en: 'Test',
                message_mk: 'Тест',
                message_en: 'Test',
            })
        ).status,
    ).toBe(404);
    expect(
        (
            await request(
                member,
                `/api/v1/organizations/${fixtures[info.project.name].organization}/notifications`,
                'GET',
            )
        ).status,
    ).toBe(404);
    expect(
        await member.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await member.screenshot({
        path: `.local/gt015-${info.project.name}.png`,
        fullPage: true,
    });
    await context.close();
});
