import { expect, test } from '@playwright/test';
test('real session authentication, CSRF, localization and responsive shell', async ({
    page,
    context,
    request,
}, testInfo) => {
    const email = `${testInfo.project.name}@browser.example.test`;
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const rejected = await request.post('/auth/login', {
        data: {
            email,
            password: 'Browser-fixture-123!',
        },
        headers: { Accept: 'application/json' },
    });
    expect(rejected.status()).toBe(419);
    await page.goto('/');
    await expect(page).toHaveURL(/\/login$/);
    await expect(
        page.getByRole('heading', { name: 'Добредојдовте во GT' }),
    ).toBeVisible();
    await page.getByLabel('Јазик').selectOption('en');
    await page.getByLabel('Email').fill(` ${email.toUpperCase()} `);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Room for your studio to grow' }),
    ).toBeVisible();
    const cookies = await context.cookies();
    const session = cookies.find((cookie) => cookie.name === 'gt_e2e_session');
    expect(session?.httpOnly).toBe(true);
    expect(session?.sameSite).toBe('Lax');
    expect(await page.evaluate(() => document.cookie)).not.toContain(
        'gt_e2e_session',
    );
    await page.reload();
    await expect(
        page.getByRole('heading', { name: 'Простор за вашето студио' }),
    ).toBeVisible();
    await page.getByLabel('Јазик').selectOption('en');
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: /Locations/ }).click();
    await page.getByRole('button', { name: 'Add location' }).click();
    const locationName = `Centar ${testInfo.project.name}`;
    await page.getByLabel('Location name').fill(locationName);
    await page.getByLabel('City', { exact: true }).fill('Bitola');
    await page.getByLabel('Country code (2 letters)').fill('MK');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(
        page.getByRole('heading', { name: locationName }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: `Edit ${locationName}`, exact: true })
        .click();
    await page
        .getByRole('combobox', { name: 'Status', exact: true })
        .selectOption('inactive');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(
        page
            .getByRole('article')
            .filter({ has: page.getByRole('heading', { name: locationName }) })
            .getByText('Inactive', { exact: true }),
    ).toBeVisible();
    const switcher = page.getByLabel('Select a studio');
    const options = await switcher
        .locator('option')
        .evaluateAll((nodes) =>
            nodes.map((node) => (node as HTMLOptionElement).value),
        );
    const first = await switcher.inputValue();
    const other = options.find((value) => value !== first)!;
    await switcher.selectOption(other);
    await expect(
        page.getByRole('heading', { name: locationName }),
    ).not.toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Изберете студио')).toHaveValue(other);
    await page.getByLabel('Јазик').selectOption('en');
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: /Staff access/ }).click();
    await expect(page.getByRole('heading', { name: 'Jovana' })).toBeVisible();
    const trainerRow = page
        .getByRole('article')
        .filter({ has: page.getByRole('heading', { name: 'Jovana' }) });
    await expect(
        trainerRow.getByText('View attendance', { exact: true }),
    ).toBeVisible();
    await expect(
        trainerRow.getByText('View payments', { exact: true }),
    ).not.toBeVisible();
    for (const [status, label] of [
        ['suspended', 'Suspended'],
        ['active', 'Active'],
    ]) {
        await page
            .getByRole('button', { name: 'Edit Jovana', exact: true })
            .click();
        await page
            .getByRole('combobox', { name: 'Status', exact: true })
            .selectOption(status);
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(trainerRow).toContainText(label);
    }
    const teacherName = `Teacher ${testInfo.project.name}`;
    const teachingRow = page.getByRole('article').filter({
        has: page.getByRole('heading', { name: teacherName, exact: true }),
    });
    await teachingRow
        .getByRole('button', {
            name: `Enable as trainer ${teacherName}`,
            exact: true,
        })
        .click();
    await expect(
        teachingRow.getByText('Trainer status: Active', { exact: true }),
    ).toBeVisible();
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Trainers', exact: true }).click();
    await page.getByRole('searchbox').fill(teacherName);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page
        .getByRole('link', {
            name: `Open trainer profile ${teacherName}`,
            exact: true,
        })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Trainer profile', exact: true }),
    ).toBeVisible();
    await page
        .getByRole('textbox', { name: /Teaching display name/ })
        .fill(`Jovana ${testInfo.project.name}`);
    await page
        .getByRole('textbox', { name: /Short bio/ })
        .fill('Fixture: Pilates instructor');
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', {
            name: `Jovana ${testInfo.project.name}`,
            exact: true,
        }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Deactivate trainer', exact: true })
        .click();
    await expect(
        page.getByRole('definition').filter({ hasText: /^Inactive$/ }),
    ).toBeVisible();
    await expect(
        page.getByRole('definition').filter({ hasText: /^Active$/ }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Activate trainer', exact: true })
        .click();
    await expect(
        page.getByRole('button', { name: 'Deactivate trainer', exact: true }),
    ).toBeEnabled();
    await expect(
        page.getByRole('definition').filter({ hasText: /^Inactive$/ }),
    ).toHaveCount(0);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${testInfo.project.name}-trainer-profile.png`,
        fullPage: true,
    });
    await page
        .getByRole('link', { name: 'Manage staff access', exact: true })
        .click();
    await page.screenshot({
        path: `test-results/${testInfo.project.name}-staff.png`,
        fullPage: true,
    });
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: /Settings/ }).click();
    await expect(page.getByLabel('Studio name')).toBeVisible();
    await page.getByLabel('Timezone').fill('America/Toronto');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('status')).toHaveText('Changes saved.');
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Clients', exact: true }).click();
    await page.getByRole('button', { name: 'Add client', exact: true }).click();
    const clientName = `Fixture-${testInfo.project.name}`;
    await page.getByLabel('First name', { exact: true }).fill(clientName);
    await page.getByLabel('Last name', { exact: true }).fill('Offline');
    await expect(page.getByLabel('Email', { exact: true })).not.toHaveAttribute(
        'required',
    );
    await page
        .getByRole('combobox', { name: 'Digital access', exact: true })
        .selectOption('offline');
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Client profile', exact: true }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Back to clients', exact: true })
        .click();
    await page.getByRole('searchbox').fill(clientName);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    expect(page.url()).not.toContain(clientName);
    await page
        .getByRole('button', {
            name: `Open profile ${clientName} Offline`,
            exact: true,
        })
        .click();
    await page
        .getByRole('button', { name: 'Edit client', exact: true })
        .click();
    await page.getByLabel('Last name', { exact: true }).fill('Updated');
    await page
        .getByLabel('Operational notes', { exact: false })
        .fill('Fixture: prefers morning sessions');
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${testInfo.project.name}-client-form.png`,
        fullPage: true,
    });
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', {
            name: `${clientName} Updated`,
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByText('Fixture: prefers morning sessions'),
    ).toBeVisible();
    await page
        .getByRole('combobox', { name: 'Change client status', exact: true })
        .selectOption('inactive');
    await page
        .getByRole('button', { name: 'Apply status', exact: true })
        .click();
    await expect(
        page.getByRole('combobox', {
            name: 'Change client status',
            exact: true,
        }),
    ).toHaveValue('inactive');
    await expect(
        page.getByRole('definition').filter({ hasText: /^Inactive$/ }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${testInfo.project.name}-client-profile.png`,
        fullPage: true,
    });
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: /Locations/ }).click();
    await page
        .getByRole('button', { name: 'Add location', exact: true })
        .click();
    const teachingLocation = `Teaching ${testInfo.project.name}`;
    await page.getByLabel('Location name').fill(teachingLocation);
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: teachingLocation, exact: true }),
    ).toBeVisible();
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Groups', exact: true }).click();
    await page.getByRole('button', { name: 'Add group', exact: true }).click();
    const groupForm = page.locator('form.management-form');
    const groupName = `Pilates ${testInfo.project.name}`;
    await groupForm.getByLabel('Group name', { exact: true }).fill(groupName);
    await groupForm
        .getByRole('combobox', { name: 'Location', exact: true })
        .selectOption({ label: teachingLocation });
    await groupForm
        .getByRole('combobox', { name: 'Primary trainer', exact: true })
        .selectOption({ label: `Jovana ${testInfo.project.name}` });
    await groupForm.getByLabel('Capacity (1–100)', { exact: true }).fill('12');
    await groupForm
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: groupName, exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('Capacity: 12', { exact: false }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Add weekly time', exact: true })
        .click();
    await page.getByLabel('Effective from', { exact: true }).fill('2035-01-01');
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', {
            name: 'Monday · 18:00–19:00',
            exact: true,
        }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Change from a future date', exact: true })
        .click();
    await page.getByLabel('Effective from', { exact: true }).fill('2035-02-01');
    await page.getByLabel('Start time', { exact: true }).fill('19:00');
    await page.getByLabel('End time', { exact: true }).fill('20:00');
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${testInfo.project.name}-schedule-form.png`,
        fullPage: true,
    });
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('heading', {
            name: 'Monday · 19:00–20:00',
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByText('2035-01-01 → 2035-01-31', { exact: true }),
    ).toBeVisible();
    await page
        .getByRole('combobox', { name: 'Change group status', exact: true })
        .selectOption('inactive');
    await page
        .getByRole('button', { name: 'Apply status', exact: true })
        .click();
    await expect(
        page.getByText('Group is inactive.', { exact: true }),
    ).toBeVisible();
    await page
        .getByRole('combobox', { name: 'Change group status', exact: true })
        .selectOption('active');
    await page
        .getByRole('button', { name: 'Apply status', exact: true })
        .click();
    await expect(
        page.getByText('Group is inactive.', { exact: true }),
    ).not.toBeVisible();
    await page.screenshot({
        path: `test-results/${testInfo.project.name}-group-profile.png`,
        fullPage: true,
    });
    await page
        .getByRole('link', { name: 'Back to groups', exact: true })
        .click();
    await page.getByRole('searchbox').fill(groupName);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: groupName, exact: true }),
    ).toBeVisible();
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Schedule', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Session agenda', exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: /Payments/ }).click();
    await expect(
        page.getByRole('heading', { name: 'Memberships', exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${testInfo.project.name}-shell.png`,
        fullPage: true,
    });
    if (testInfo.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    const response = await context.request.get('/api/v1/me', {
        headers: { Accept: 'application/json' },
    });
    expect(response.status()).toBe(401);
    expect(errors).toEqual([]);
});
