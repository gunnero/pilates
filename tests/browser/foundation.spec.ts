import { expect, test } from '@playwright/test';
test('real session authentication, CSRF, localization and responsive shell', async ({
    page,
    context,
    request,
}, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const rejected = await request.post('/auth/login', {
        data: {
            email: 'browser@example.test',
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
    await page.getByLabel('Email').fill(' BROWSER@example.test ');
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
    await expect(page.getByText('No accessible locations yet.')).toBeVisible();
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
    await page.getByRole('link', { name: /Payments/ }).click();
    await expect(
        page.getByText('This section is not available yet.'),
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
