import { expect, test } from '../support/test';

test('a platform admin creates a company, changes its address, suspends and reopens it', async ({
    page,
}) => {
    const name = 'Browser Platform Company';

    await page.goto('/platform/companies');
    await expect(
        page.getByRole('heading', { name: 'Companies', exact: true }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Add company' }).click();
    const form = page.getByRole('dialog');
    await form.getByLabel('Company name').fill(name);
    await expect(form.getByLabel('Address')).toHaveValue(
        'browser-platform-company',
    );
    await form.getByLabel("Admin's name").fill('First Admin');
    await form
        .getByLabel("Admin's email")
        .fill('first-admin@browser-test.example');
    await form.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        page.getByText('first-admin@browser-test.example was invited'),
    ).toBeVisible();
    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row.getByText('/browser-platform-company')).toBeVisible();
    await expect(row.getByText('0 members, 1 invited')).toBeVisible();

    await page.getByRole('button', { name: `Edit ${name}` }).click();
    await page
        .getByRole('dialog')
        .getByLabel('Address')
        .fill('browser-renamed');
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Save' })
        .click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        page.getByText('/browser-platform-company keep working'),
    ).toBeVisible();
    await expect(row.getByText('/browser-renamed')).toBeVisible();

    await page.getByRole('button', { name: `Suspend ${name}` }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Suspend' })
        .click();
    await expect(row.getByText('Suspended')).toBeVisible();

    await page.getByRole('button', { name: `Reopen ${name}` }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Reopen' })
        .click();
    await expect(row.getByText('Active')).toBeVisible();

    await page
        .getByRole('searchbox', { name: 'Search companies' })
        .fill('renamed');
    await expect(page.getByRole('row')).toHaveCount(2);
    await expect(row).toBeVisible();
});

test('a platform admin starts on the platform pages, which are in the menu', async ({
    page,
}) => {
    await page.goto('/');

    await expect(page).toHaveURL(/\/platform\/companies$/);
    await expect(
        page.locator('a[href="/platform/companies"]', { hasText: 'Companies' }),
    ).toBeVisible();
});
