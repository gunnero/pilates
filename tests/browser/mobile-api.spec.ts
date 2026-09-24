import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { ApiClient } from '../../apps/mobile/src/api/client';
import { MemberApi } from '../../apps/mobile/src/api/member';
import { SessionController } from '../../apps/mobile/src/auth/session';
import { parseLink } from '../../apps/mobile/src/navigation/links';
// Native transport/session integration, not an iOS/Android device test. Uses
// the same guarded gt_test server and independent fixtures as browser E2E.
test('native API A–I: activation, booking, FIFO, histories, studio/account isolation and revocation', async ({
    page,
}, info) => {
    test.setTimeout(60000);
    const name = `native-${info.project.name}`;
    const fixture = JSON.parse(
        readFileSync('.local/browser-member-fixture.json', 'utf8'),
    )[name] as {
        organization: string;
        second: string;
        session: string;
        attendance_session: string;
    };
    const origin = 'http://127.0.0.1:8011';
    const staff = `/api/v1/organizations/${fixture.organization}`;
    const email = `member-${name}@browser.example.test`;
    const password = 'Browser-fixture-123!';
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email', { exact: true })
        .fill(`member-staff-${name}@browser.example.test`);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    async function staffPost(path: string, body: unknown) {
        return page.evaluate(
            async ({ path, body }) => {
                const csrf = document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice(11);
                const response = await fetch(path, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
                    },
                    body: JSON.stringify(body),
                });
                if (!response.ok)
                    throw new Error(`Fixture setup failed ${response.status}`);
                return response.json();
            },
            { path, body },
        );
    }
    const member = await staffPost(`${staff}/members`, {
        first_name: 'Native',
        last_name: 'Member',
        digital_access_mode: 'offline',
        preferred_locale: 'en',
    });
    const plan = await staffPost(`${staff}/membership-plans`, {
        name: 'Native monthly',
        price_minor: 150000,
        duration_unit: 'months',
        duration_count: 1,
        status: 'active',
    });
    const membership = await staffPost(`${staff}/memberships`, {
        member_id: member.data.id,
        plan_id: plan.data.id,
        starts_on: new Date().toLocaleDateString('en-CA', {
            timeZone: 'Europe/Skopje',
        }),
    });
    await staffPost(`${staff}/cash-payments`, {
        membership_id: membership.data.id,
        amount_minor: 100000,
        paid_at: new Date().toISOString(),
        request_id: crypto.randomUUID(),
    });
    await staffPost(
        `${staff}/sessions/${fixture.attendance_session}/reservations`,
        { member_id: member.data.id },
    );
    await staffPost(
        `${staff}/sessions/${fixture.attendance_session}/attendance`,
        { member_id: member.data.id, status: 'present' },
    );
    await staffPost(`${staff}/members/${member.data.id}/invitations`, {
        email,
    });
    const capture = JSON.parse(
        readFileSync(
            `.local/browser-invite-${createHash('sha256').update(email).digest('hex')}.json`,
            'utf8',
        ),
    ) as { url: string };
    const activation = parseLink(
        `gtstudio://activate${new URL(capture.url).hash}`,
    );
    expect(activation?.kind).toBe('activate');
    if (!activation || !('token' in activation))
        throw new Error('Invalid fixture invitation');
    const api = new MemberApi(new ApiClient(origin));
    let stored: string | null = null;
    const cache = new Map<string, unknown>();
    const controller = new SessionController(
        api,
        {
            read: async () => stored,
            write: async (value) => {
                stored = value;
            },
            clear: async () => {
                stored = null;
            },
        },
        () => cache.clear(),
        async () => {},
    );
    await api.activate(email, activation.token, password);
    await controller.login(email, password);
    expect(controller.snapshot().phase).toBe('ready');
    const organizations = (await api.organizations()).data;
    expect(organizations).toHaveLength(2);
    const org = organizations.find((o) => o.id === fixture.organization)!;
    await controller.select(org);
    expect((await api.profile(org.id)).data.first_name).toBe('Native');
    await api.saveLocale(org.id, 'mk');
    expect((await api.profile(org.id)).data.preferred_locale).toBe('mk');
    const groups = await api.groups(org.id);
    expect((await api.group(org.id, groups.data[0].id)).data.name).toBe(
        'Member Pilates',
    );
    expect((await api.sessions(org.id)).data.length).toBeGreaterThan(0);
    expect(
        (await api.reserve(org.id, fixture.session, false)).data.outcome,
    ).toBe('reserved');
    const booked = (await api.session(org.id, fixture.session)).data;
    expect(booked.reservation?.status).toBe('active');
    expect(
        (await api.sessions(org.id, 1, 'reserved')).data.some(
            (s) => s.id === fixture.session,
        ),
    ).toBe(true);
    const other = new MemberApi(new ApiClient(origin));
    other.client.token = (
        await other.login(`member-wait-${name}@browser.example.test`, password)
    ).data.token;
    expect(
        (await other.reserve(org.id, fixture.session, true)).data.outcome,
    ).toBe('waitlisted');
    expect((await other.waiting(org.id)).data[0].status).toBe('waiting');
    await api.cancel(org.id, 'reservations', booked.reservation!.id);
    expect(
        (await api.session(org.id, fixture.session)).data.reservation?.status,
    ).toBe('cancelled');
    expect(
        (await other.session(org.id, fixture.session)).data.reservation?.status,
    ).toBe('active');
    expect((await api.attendance(org.id)).data[0].status).toBe('present');
    expect((await api.memberships(org.id)).data[0].plan_name).toBe(
        'Native monthly',
    );
    expect((await api.payments(org.id)).data[0].amount_minor).toBe(100000);
    execFileSync('php', ['tests/browser/deliver.php', org.id]);
    const inbox = await api.notices(org.id);
    expect(inbox.data.length).toBeGreaterThan(0);
    expect((await api.notice(org.id, inbox.data[0].id)).data.id).toBe(
        inbox.data[0].id,
    );
    await api.read(
        org.id,
        inbox.data.map((n) => n.id),
    );
    expect((await api.notices(org.id)).unread_count).toBe(0);
    await api.savePreferences(org.id, {
        reminders_enabled: false,
        expiry_reminders_enabled: true,
    });
    expect((await api.preferences(org.id)).data.reminders_enabled).toBe(false);
    cache.set('private', 'Native');
    await controller.select(
        organizations.find((o) => o.id === fixture.second)!,
    );
    expect(cache.size).toBe(0);
    expect((await api.profile(fixture.second)).data.first_name).toBe('Second');
    await expect(
        api.session(fixture.second, fixture.session),
    ).rejects.toMatchObject({ kind: 'missing' });
    const revoked = api.client.token;
    await controller.logout();
    expect(stored).toBeNull();
    expect(controller.snapshot().account).toBeNull();
    await controller.login(
        `member-wait-${name}@browser.example.test`,
        password,
    );
    expect((await api.profile(org.id)).data.first_name).toBe('Waiting');
    expect(cache.size).toBe(0);
    await controller.logout();
    api.client.token = revoked;
    await expect(api.account()).rejects.toMatchObject({ kind: 'expired' });
    expect(controller.snapshot().phase).toBe('anonymous');
    expect(parseLink('gtstudio://session/../../staff')).toBeNull();
    // Narrow account-security consumer: successful password change revokes the native bearer.
    await controller.login(email, password);
    await api.password(password, 'Browser-fixture-new-123!');
    await expect(api.account()).rejects.toMatchObject({ kind: 'expired' });
    if (info.project.name === 'desktop') {
        await api.forgot(email);
        const reset = JSON.parse(
            readFileSync(
                `.local/browser-reset-${createHash('sha256').update(email).digest('hex')}.json`,
                'utf8',
            ),
        ) as { token: string };
        await api.reset(email, reset.token, password);
        await controller.login(email, password);
        expect(controller.snapshot().phase).toBe('ready');
        await controller.logout();
    }
});
