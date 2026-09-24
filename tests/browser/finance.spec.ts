import { expect, test } from '@playwright/test';
test('client membership, cash, reversal and correct payment', async ({
    page,
}, info) => {
    await page.goto('/login');
    await page.getByLabel('Јазик').selectOption('en');
    await page
        .getByLabel('Email')
        .fill(`finance-${info.project.name}@browser.example.test`);
    await page
        .getByLabel('Password', { exact: true })
        .fill('Browser-fixture-123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).not.toHaveURL(/login/);
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: /Clients/ }).click();
    await page.getByRole('button', { name: 'Finance Client' }).click();
    await page
        .getByRole('button', { name: 'Add membership', exact: true })
        .click();
    await page
        .getByRole('combobox', { name: 'Membership plan', exact: true })
        .selectOption({ label: 'Monthly cash' });
    await expect(page.getByText(/Proposed period and price/)).toContainText(
        '1500.00 MKD',
    );
    await page
        .getByRole('button', { name: 'Confirm membership', exact: true })
        .click();
    await page
        .getByRole('button', { name: 'Membership details', exact: true })
        .click();
    const detail = page.getByRole('region', {
        name: 'Membership details',
        exact: true,
    });
    async function pay() {
        await detail
            .getByRole('button', { name: 'Record cash payment', exact: true })
            .click();
        await detail.getByLabel('Amount (MKD)', { exact: true }).fill('1500');
        await detail.getByRole('button', { name: 'Review payment' }).click();
        const confirm = detail.getByRole('group', {
            name: 'Confirm cash received',
        });
        await expect(confirm).toContainText('Finance Client');
        await expect(confirm).toContainText('1500.00 MKD');
        await confirm
            .getByRole('button', { name: 'Confirm cash received' })
            .click();
    }
    await pay();
    await expect(
        detail.locator('dd').filter({ hasText: /^Paid$/ }),
    ).toBeVisible();
    await detail
        .getByRole('button', { name: 'Reverse payment', exact: true })
        .click();
    await detail
        .getByRole('combobox', { name: 'Reason', exact: true })
        .selectOption('wrong_amount');
    await detail.getByRole('button', { name: 'Confirm reversal' }).click();
    await expect(detail.getByText(/Reversed by: Finance owner/)).toBeVisible();
    await expect(
        detail.locator('dd').filter({ hasText: /^Unpaid$/ }),
    ).toBeVisible();
    await pay();
    await expect(
        detail.locator('dd').filter({ hasText: /^Paid$/ }),
    ).toBeVisible();
    await expect(detail.locator('article')).toHaveCount(2);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `test-results/${info.project.name}-finance.png`,
        fullPage: true,
    });
    if (info.project.name === 'mobile')
        await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/login/);
});
