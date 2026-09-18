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
