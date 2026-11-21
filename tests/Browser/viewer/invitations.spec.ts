import { inviteCompany } from '../support/test-data';
import { expect, test } from '../support/test';

test('the viewer accepts an invitation and lands in the new company', async ({
    page,
}) => {
    await page.goto('/invitations');
    await expect(
        page.getByRole('heading', { name: 'Invitations', exact: true }),
    ).toBeVisible();
    await expect(page.getByText(inviteCompany.name)).toBeVisible();
    await expect(page.getByText('Invited by Browser Inviter')).toBeVisible();

    await page
        .getByRole('button', {
            name: `Accept the invitation from ${inviteCompany.name}`,
        })
        .click();

    await expect(page).toHaveURL(new RegExp(`${inviteCompany.url}$`));
    await expect(
        page.getByRole('heading', { name: 'Suppliers needing attention' }),
    ).toBeVisible();
});
