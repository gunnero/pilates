import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
async function english(page: Page) {
    await page.getByLabel('Јазик').selectOption('en');
}
async function login(page: Page, email: string, staff = false) {
    await page.goto(staff ? '/login' : '/member/login');
    await english(page);
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
}
async function request(page: Page, path: string, data?: unknown) {
    return page.evaluate(
        async ({ path, data }) => {
            const token = document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const r = await fetch(path, {
                method: data === undefined ? 'GET' : 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(token
                        ? { 'X-XSRF-TOKEN': decodeURIComponent(token) }
                        : {}),
                },
                ...(data === undefined ? {} : { body: JSON.stringify(data) }),
            });
            return { status: r.status, body: await r.json() };
        },
        { path, data },
    );
}
test('staff invitation, member self-service, FIFO, history, IDOR and studio isolation', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(60000);
    const fixture = JSON.parse(
        readFileSync('.local/browser-member-fixture.json', 'utf8'),
    )[info.project.name] as {
        organization: string;
        second: string;
        session: string;
        other_member: string;
        attendance_session: string;
    };
    const email = `member-${info.project.name}@browser.example.test`;
    const staff = `/api/v1/organizations/${fixture.organization}`;
    const self = `/api/v1/member/organizations/${fixture.organization}`;
    await login(
        page,
        `member-staff-${info.project.name}@browser.example.test`,
        true,
    );
    const created = await request(page, `${staff}/members`, {
        first_name: 'Self',
        last_name: 'Browser',
        digital_access_mode: 'offline',
        preferred_locale: 'en',
    });
    expect(created.status).toBe(201);
    const member = created.body.data.id as string;
    const plan = await request(page, `${staff}/membership-plans`, {
        name: 'Self monthly',
        price_minor: 150000,
        duration_unit: 'months',
        duration_count: 1,
        status: 'active',
    });
    expect(plan.status).toBe(201);
    const start = new Date().toLocaleDateString('en-CA', {
        timeZone: 'Europe/Skopje',
    });
    const membership = await request(page, `${staff}/memberships`, {
        member_id: member,
        plan_id: plan.body.data.id,
        starts_on: start,
    });
    expect(membership.status).toBe(201);
    const payment = await request(page, `${staff}/cash-payments`, {
        membership_id: membership.body.data.id,
        amount_minor: 100000,
        paid_at: new Date().toISOString(),
        request_id: crypto.randomUUID(),
    });
    expect(payment.status).toBe(201);
    const reservedAttendance = await request(
        page,
        `${staff}/sessions/${fixture.attendance_session}/reservations`,
        { member_id: member },
    );
    expect(reservedAttendance.status).toBe(200);
    const attendance = await request(
        page,
        `${staff}/sessions/${fixture.attendance_session}/attendance`,
        { member_id: member, status: 'present' },
    );
    expect(attendance.status).toBe(200);
    // Staff opens the same existing domain record and sends its activation invitation through the UI.
    await page.goto('/members');
    await english(page);
    await page
        .getByRole('button', { name: 'Open profile Self Browser', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Self Browser', exact: true }),
    ).toBeVisible();
    await page.getByLabel('Email', { exact: true }).last().fill(email);
    await page.getByRole('button', { name: 'Send invitation' }).click();
    await expect(
        page.getByText('Invitation accepted by the delivery service.'),
    ).toBeVisible();
    const capture = JSON.parse(
        readFileSync(
            `.local/browser-invite-${createHash('sha256').update(email).digest('hex')}.json`,
            'utf8',
        ),
    ) as { url: string };
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.goto(capture.url);
    await english(page);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByLabel('Confirm password').fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Save password' }).click();
    await expect(
        page.getByText('Account activated. You can now sign in.'),
    ).toBeVisible();
    await login(page, email);
    const profile = await request(page, `${self}/profile`);
    expect(profile.body.data.id).toBe(member);
    await page
        .getByRole('button', { name: 'Available groups', exact: true })
        .first()
        .click();
    await page
        .getByRole('button', { name: 'View sessions and availability' })
        .click();
    await page.getByRole('button', { name: 'Session details' }).last().click();
    await page.getByRole('button', { name: 'Reserve a spot' }).click();
    await expect(
        page.getByRole('button', { name: 'Cancel reservation' }),
    ).toBeVisible();
    const booked = await request(page, `${self}/sessions/${fixture.session}`);
    const reservation = booked.body.data.reservation.id as string;
    const otherContext = await browser.newContext({
        viewport: page.viewportSize() ?? { width: 390, height: 844 },
    });
    const other = await otherContext.newPage();
    await login(other, `member-wait-${info.project.name}@browser.example.test`);
    await other
        .getByRole('button', { name: 'Available groups', exact: true })
        .first()
        .click();
    await other
        .getByRole('button', { name: 'View sessions and availability' })
        .click();
    await other.getByRole('button', { name: 'Session details' }).last().click();
    await other.getByRole('button', { name: 'Join waiting list' }).click();
    await expect(
        other.getByRole('button', { name: 'Leave waiting list' }),
    ).toBeVisible();
    expect(
        (await request(other, `${self}/reservations/${reservation}`)).status,
    ).toBe(404);
    expect(
        (await request(other, `${self}/reservations/${reservation}/cancel`, {}))
            .status,
    ).toBe(404);
    await page.getByRole('button', { name: 'Cancel reservation' }).click();
    await expect(page.getByText('Reservation: Cancelled')).toBeVisible();
    execFileSync('php', ['tests/browser/deliver.php', fixture.organization]);
    await other.reload();
    await english(other);
    await expect(
        other
            .getByRole('article')
            .filter({ hasText: 'Scheduled' })
            .getByRole('heading', { name: 'Member Pilates', exact: true }),
    ).toBeVisible();
    await other
        .getByRole('button', { name: 'Notifications', exact: true })
        .click();
    await expect(other.getByText(/A place is yours/i).first()).toBeVisible();
    for (const [name, text] of [
        ['Memberships', 'Self monthly'],
        ['Payments', 'Recorded'],
        ['Attendance', 'Present'],
    ]) {
        await page.getByRole('button', { name, exact: true }).click();
        await expect(
            page.getByText(text, { exact: false }).first(),
        ).toBeVisible();
    }
    for (const suffix of [
        `memberships/${membership.body.data.id}`,
        `payments/${payment.body.data.id}`,
        `attendance/${attendance.body.data.id}`,
        `profile/${member}`,
    ])
        expect((await request(other, `${self}/${suffix}`)).status).toBe(404);
    const notices = await request(page, `${staff}/notifications`);
    expect(notices.body.data.length).toBeGreaterThan(0);
    expect(
        (
            await request(
                other,
                `${staff}/notifications/${notices.body.data[0].id}`,
            )
        ).status,
    ).toBe(404);
    await otherContext.close();
    await page.getByRole('button', { name: 'My profile' }).click();
    await expect(page.getByLabel('First name')).toHaveValue('Self');
    await page
        .getByRole('combobox', { name: 'Select a studio' })
        .selectOption(fixture.second);
    await page.getByRole('button', { name: 'My profile' }).click();
    await expect(page.getByLabel('First name')).toHaveValue('Second');
    expect(
        (
            await request(
                page,
                `/api/v1/member/organizations/${fixture.second}/reservations/${reservation}`,
            )
        ).status,
    ).toBe(404);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${info.project.name}-member.png`,
        fullPage: true,
    });
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/member\/login/);
});
