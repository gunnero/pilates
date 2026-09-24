import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { ApiClient, ApiError } from '../../apps/mobile/src/api/client';
import { MemberApi } from '../../apps/mobile/src/api/member';
import { parseCheckIn } from '../../apps/mobile/src/check-in/parse';

test('secure QR A–K: member attendance, rotation, rejection, live roster and fallback', async ({
    page,
}, info) => {
    test.setTimeout(60000);
    const fixture = JSON.parse(
        readFileSync('.local/browser-check-in-fixture.json', 'utf8'),
    )[info.project.name] as {
        organization: string;
        session: string;
        members: Record<string, string>;
        expired: string;
    };
    const base = `/api/v1/organizations/${fixture.organization}/sessions/${fixture.session}`;
    const password = 'Browser-fixture-123!';
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`qr-staff-${info.project.name}@browser.example.test`);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    await page.goto(`/schedule/${fixture.session}`);
    await page.getByLabel('Јазик').selectOption('en');
    const roster = page.getByRole('region', {
        name: 'Attendance',
        exact: true,
    });
    // Capture the actual authorized display response, never print it or retain the QR screenshot.
    const issuing = page.waitForResponse(
        (r) =>
            r.url().endsWith('/attendance/qr') &&
            r.request().method() === 'POST',
    );
    await roster.getByRole('button', { name: 'Show check-in QR' }).click();
    const issued = (await (await issuing).json()).data;
    await expect(roster.locator('svg')).toBeVisible();
    const scanned = parseCheckIn(issued.payload)!;
    expect(scanned.organization).toBe(fixture.organization);
    async function member(name: string) {
        const api = new MemberApi(new ApiClient('http://127.0.0.1:8011'));
        api.client.token = (
            await api.login(
                `qr-${name}-${info.project.name}@browser.example.test`,
                password,
            )
        ).data.token;
        return api;
    }
    const first = await member('first');
    const foreign = JSON.parse(
        readFileSync('.local/browser-check-in-fixture.json', 'utf8'),
    )[info.project.name === 'desktop' ? 'mobile' : 'desktop'] as {
        organization: string;
    };
    await expect(
        first.checkIn(
            foreign.organization,
            fixture.session,
            scanned.credential,
        ),
    ).rejects.toMatchObject({ kind: 'missing' });
    const second = await member('second');
    const waiting = await member('waiting');
    const fallback = await member('fallback');
    await expect(
        first.checkIn(fixture.organization, fixture.session, fixture.expired),
    ).rejects.toMatchObject({ kind: 'conflict', code: 'qr_expired' });
    await expect(
        first.checkIn(fixture.organization, fixture.session, 'bad'),
    ).rejects.toMatchObject({ code: 'qr_invalid' });
    expect(
        (
            await first.checkIn(
                scanned.organization,
                scanned.session,
                scanned.credential,
            )
        ).data.outcome,
    ).toBe('checked_in');
    expect(
        (
            await first.checkIn(
                scanned.organization,
                scanned.session,
                scanned.credential,
            )
        ).data.outcome,
    ).toBe('already_present');
    await expect(
        waiting.checkIn(
            scanned.organization,
            scanned.session,
            scanned.credential,
        ),
    ).rejects.toMatchObject({ code: 'waiting' });
    await expect(
        fallback.checkIn(
            scanned.organization,
            scanned.session,
            scanned.credential,
        ),
    ).rejects.toMatchObject({ code: 'reservation_required' });
    await expect(
        roster.locator('article').filter({ hasText: 'First QR' }),
    ).toContainText('Present', { timeout: 15000 });
    expect((await first.attendance(fixture.organization)).data[0].method).toBe(
        'qr',
    );
    expect((await second.attendance(fixture.organization)).data).toHaveLength(
        0,
    );
    async function staffPost(path: string, body: unknown) {
        return page.evaluate(
            async ({ path, body }) => {
                const csrf = document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice(11);
                const r = await fetch(path, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
                    },
                    body: JSON.stringify(body),
                });
                return { status: r.status, body: await r.json() };
            },
            { path, body },
        );
    }
    const rotation = await staffPost(`${base}/attendance/qr`, {});
    expect(rotation.status).toBe(200);
    expect(rotation.body.data.token === scanned.credential).toBe(false);
    expect(
        (
            await second.checkIn(
                fixture.organization,
                fixture.session,
                rotation.body.data.token,
            )
        ).data.outcome,
    ).toBe('checked_in');
    const manual = await staffPost(`${base}/attendance`, {
        member_id: fixture.members.First,
        status: 'present',
    });
    expect(manual.status).toBe(200);
    expect(manual.body.data.method).toBe('qr');
    const denied = await first.client
        .request(`${base}/attendance/qr`, 'POST', {})
        .catch((e) => e);
    expect(denied).toBeInstanceOf(ApiError);
    expect([403, 404]).toContain(
        (await staffPost(`${base}/cancel`, {})).status,
    ); // trainer has no session management grant
    await roster.getByRole('button', { name: 'Hide QR' }).click();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${info.project.name}-secure-check-in.png`,
        fullPage: true,
    });
    for (const api of [first, second, waiting, fallback]) await api.logout();
});
