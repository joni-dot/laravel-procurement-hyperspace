import { expect, test } from '../support/test';

test('a platform admin creates a demo company, which the list marks as a demo', async ({
    page,
}) => {
    const name = 'Browser Demo Company';

    await page.goto('/platform/companies');
    await page.getByRole('button', { name: 'Create demo company' }).click();

    const form = page.getByRole('dialog');
    await expect(
        form.getByRole('heading', { name: 'Create demo company' }),
    ).toBeVisible();
    await form.getByLabel('Company name').fill(name);
    await expect(form.getByLabel('Address')).toHaveValue(
        'browser-demo-company',
    );
    await form.getByLabel("Admin's name").fill('Demo Admin');
    await form
        .getByLabel("Admin's email")
        .fill('demo-admin@browser-test.example');
    await form.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        page.getByText(
            'Demo company created. demo-admin@browser-test.example was invited to be its admin.',
        ),
    ).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row.getByText('Demo', { exact: true })).toBeVisible();
    await expect(row.getByText('/browser-demo-company')).toBeVisible();
    await expect(row.getByText('0 members, 1 invited')).toBeVisible();
});
