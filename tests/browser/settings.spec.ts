import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { ApiClient } from '../../apps/mobile/src/api/client';
import { MemberApi } from '../../apps/mobile/src/api/member';

test('studio settings defaults, policy enforcement, locale, timezone and responsive controls', async ({
    page,
    browser,
}, info) => {
    const fixtures = JSON.parse(
        readFileSync('.local/browser-settings-fixture.json', 'utf8'),
    );
    const f = fixtures[info.project.name] as {
        organization: string;
        empty: string;
        session: string;
        members: Record<string, string>;
    };
    const foreign = fixtures[
        info.project.name === 'desktop' ? 'mobile' : 'desktop'
    ].organization as string;
    const base = `/api/v1/organizations/${f.organization}`;
    const password = 'Browser-fixture-123!';
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`policy-staff-${info.project.name}@browser.example.test`);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.getByLabel('Select a studio').selectOption(f.organization);
    await page.goto('/settings');
    await page.getByRole('banner').getByLabel('Јазик').selectOption('en');
    await expect(
        page.getByRole('heading', { name: 'Studio policies' }),
    ).toBeVisible();
    const policies = page.getByRole('region', { name: 'Studio policies' });
    await expect(policies.getByLabel('Member self-reservation')).toHaveValue(
        'default',
    );
    await expect(
        policies.getByLabel('QR lifetime (seconds; maximum 90)'),
    ).toHaveValue('90');
    async function staff(path: string, method: string, body?: unknown) {
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
                    ...(body === undefined
                        ? {}
                        : { body: JSON.stringify(body) }),
                });
                return { status: r.status, body: await r.json() };
            },
            { path, method, body },
        );
    }
    async function member(name: string) {
        const api = new MemberApi(new ApiClient('http://127.0.0.1:8011'));
        api.client.token = (
            await api.login(
                `policy-${name}-${info.project.name}@browser.example.test`,
                password,
            )
        ).data.token;
        return api;
    }
    const first = await member('first');
    const fallback = await member('fallback');
    expect(
        (await first.settings(f.organization)).data.self_check_in_enabled,
    ).toBe(true);
    const qr = (
        await staff(`${base}/sessions/${f.session}/attendance/qr`, 'POST', {})
    ).body.data;
    // Actual settings form and confirmation, followed by the native API client against the server.
    await policies.getByLabel('Session reminder lead time (hours)').fill('1');
    await policies.getByLabel('Member self-reservation').selectOption('false');
    await policies.getByLabel('Member QR check-in').selectOption('false');
    await policies
        .getByLabel('Cancellation cutoff (minutes before start)')
        .fill('60');
    await policies.getByRole('button', { name: 'Save changes' }).click();
    await policies.getByRole('button', { name: 'Apply policies' }).click();
    await expect(policies.getByRole('status')).toContainText('Changes saved');
    const generate = () =>
        JSON.parse(
            execFileSync(
                'php',
                ['tests/browser/generate-reminders.php', f.organization],
                { encoding: 'utf8' },
            ),
        );
    expect(generate().created).toBe(2);
    expect(generate().created).toBe(0);
    expect(
        (await staff(`${base}/settings`, 'PATCH', { reminder_lead_hours: 2 }))
            .status,
    ).toBe(200);
    expect(generate().created).toBe(0);
    expect(
        (await first.settings(f.organization)).data.self_check_in_enabled,
    ).toBe(false);
    await expect(
        fallback.reserve(f.organization, f.session, true),
    ).rejects.toMatchObject({ code: 'policy' });
    const memberContext = await browser.newContext({
        viewport: page.viewportSize() ?? { width: 390, height: 844 },
    });
    const web = await memberContext.newPage();
    await web.goto('/member/login');
    await web.getByLabel('Јазик').selectOption('en');
    await web
        .getByLabel('Email', { exact: true })
        .fill(`policy-first-${info.project.name}@browser.example.test`);
    await web.getByLabel('Password', { exact: true }).fill(password);
    await web.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(web).not.toHaveURL(/login/);
    await web
        .getByRole('button', { name: 'Available groups', exact: true })
        .first()
        .click();
    await web
        .getByRole('button', { name: 'View sessions and availability' })
        .click();
    await web.getByRole('button', { name: 'Session details' }).last().click();
    await expect(
        web.getByRole('button', { name: 'Reserve a spot' }),
    ).toHaveCount(0);
    await expect(
        web.getByRole('button', { name: 'Cancel reservation' }),
    ).toHaveCount(0);
    const blockedWeb = await web.evaluate(async ({ organization, session }) => {
        const csrf = document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice(11);
        return (
            await fetch(
                `/api/v1/member/organizations/${organization}/sessions/${session}/reservations`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
                    },
                    body: '{}',
                },
            )
        ).status;
    }, f);
    expect(blockedWeb).toBe(409);
    await memberContext.close();
    const current = (await first.session(f.organization, f.session)).data;
    expect(current.can_reserve).toBe(false);
    expect(current.reservation?.can_cancel).toBe(false);
    await expect(
        first.cancel(f.organization, 'reservations', current.reservation!.id),
    ).rejects.toMatchObject({ code: 'policy' });
    await expect(
        first.checkIn(f.organization, f.session, qr.token),
    ).rejects.toMatchObject({ code: 'policy' });
    expect(
        (
            await staff(`${base}/sessions/${f.session}/attendance`, 'POST', {
                member_id: f.members.First,
                status: 'present',
            })
        ).status,
    ).toBe(200);
    expect(
        (
            await staff(`${base}/settings`, 'PATCH', {
                self_check_in_enabled: true,
                check_in_early_minutes: 0,
            })
        ).status,
    ).toBe(200);
    await expect(
        first.checkIn(f.organization, f.session, qr.token),
    ).rejects.toMatchObject({ code: 'too_early' });
    expect((await staff(base, 'PATCH', { locale: 'mk' })).status).toBe(200);
    expect((await first.settings(f.organization)).data.locale).toBe('en');
    expect(
        (await staff(`/api/v1/organizations/${foreign}/settings`, 'GET'))
            .status,
    ).toBe(404);
    await expect(
        first.client.request(`${base}/settings`, 'PATCH', {
            self_reservation_enabled: true,
        }),
    ).rejects.toMatchObject({ kind: 'missing' });
    page.on('dialog', (dialog) => void dialog.accept());
    await page
        .getByLabel('Timezone', { exact: false })
        .selectOption('America/New_York');
    const rejected = page.waitForResponse(
        (r) => r.url().endsWith(base) && r.request().method() === 'PATCH',
    );
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .first()
        .click();
    expect((await rejected).status()).toBe(409);
    await expect(page.getByRole('alert').first()).toBeVisible();
    await page.getByLabel('Select a studio').selectOption(f.empty);
    await page
        .getByLabel('Timezone', { exact: false })
        .selectOption('America/New_York');
    const accepted = page.waitForResponse(
        (r) => r.url().endsWith(f.empty) && r.request().method() === 'PATCH',
    );
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .first()
        .click();
    expect((await accepted).status()).toBe(200);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `.local/gt014-${info.project.name}.png`,
        fullPage: true,
    });
});
