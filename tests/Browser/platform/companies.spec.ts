import { companyWithoutAdmin, otherCompany, users } from '../support/test-data';
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

    // The first admin's address was mistyped: send the invitation to the corrected one.
    await page
        .getByRole('button', { name: `Invite the first admin of ${name}` })
        .click();
    const firstAdmin = page.getByRole('dialog');
    await expect(firstAdmin.getByLabel("Admin's email")).toHaveValue(
        'first-admin@browser-test.example',
    );
    await firstAdmin
        .getByLabel("Admin's email")
        .fill('corrected-admin@browser-test.example');
    await firstAdmin.getByRole('button', { name: 'Send invitation' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        page.getByText(
            `corrected-admin@browser-test.example was invited to be the admin of ${name}`,
        ),
    ).toBeVisible();
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

test('a company whose admins have all left is offered one of its own members as admin', async ({
    page,
}) => {
    // The seeded other company has one member (the buyer) and no admin. The dialog is only opened
    // here: making the buyer its admin would change what other tests see.
    await page.goto('/platform/companies?search=browser-other');
    await page
        .getByRole('button', {
            name: `Choose a new admin for ${otherCompany.name}`,
        })
        .click();

    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByRole('heading', { name: 'Choose a new admin' }),
    ).toBeVisible();
    await expect(dialog.getByLabel('Member', { exact: true })).toContainText(
        users.buyer,
    );
    await expect(dialog.getByLabel("Admin's email")).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
});

test('a platform admin makes a member the admin of a company whose admins have all left', async ({
    page,
}) => {
    const { name, member, memberEmail } = companyWithoutAdmin;

    await page.goto(`/platform/companies?search=${companyWithoutAdmin.search}`);
    await page
        .getByRole('button', { name: `Choose a new admin for ${name}` })
        .click();

    // The members are searched on the server; only the matching one is offered.
    const dialog = page.getByRole('dialog');
    const members = dialog.getByLabel('Member', { exact: true });
    await expect(members.locator('option')).toHaveCount(2);
    await dialog.getByLabel('Search members').fill(memberEmail);
    await expect(members.locator('option')).toHaveCount(1);
    await expect(members).toContainText(member);

    await dialog.getByRole('button', { name: 'Make admin' }).click();

    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        page.getByText(`${member} is now the admin of ${name}.`),
    ).toBeVisible();
    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row.getByText('2 members')).toBeVisible();
    await expect(
        row.getByRole('button', { name: `Choose a new admin for ${name}` }),
    ).toHaveCount(0);
    await expect(
        row.getByRole('button', { name: `Invite the first admin of ${name}` }),
    ).toHaveCount(0);
});
