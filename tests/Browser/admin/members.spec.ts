import { company } from '../support/test-data';
import { expect, test } from '../support/test';

test('an admin invites someone, changes their role and withdraws the invitation', async ({
    page,
}) => {
    await page.goto(`${company}/admin/users`);

    await page.getByRole('button', { name: 'Invite user' }).click();
    const invite = page.getByRole('dialog');
    await invite.getByLabel('Name').fill('Browser Invitee');
    await invite
        .getByLabel('Email address')
        .fill('invitee@browser-test.example');
    await invite.getByRole('button', { name: 'Send invitation' }).click();
    await expect(invite).toHaveCount(0);

    const row = page.getByRole('row', { name: /Browser Invitee/ });
    await expect(row.getByText('Invited', { exact: true })).toBeVisible();

    await row
        .getByRole('button', { name: 'Actions for Browser Invitee' })
        .click();
    await page.getByRole('menuitem', { name: 'Change role' }).click();
    const changeRole = page.getByRole('dialog');
    await changeRole.getByLabel('Role').click();
    await page.getByRole('option', { name: 'Buyer' }).click();
    await changeRole.getByRole('button', { name: 'Save' }).click();
    await expect(changeRole).toHaveCount(0);
    await expect(row.getByText('Buyer', { exact: true })).toBeVisible();

    await row
        .getByRole('button', { name: 'Actions for Browser Invitee' })
        .click();
    await page.getByRole('menuitem', { name: 'Deactivate' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Deactivate' })
        .click();
    await expect(row.getByText('Deactivated', { exact: true })).toBeVisible();
});
