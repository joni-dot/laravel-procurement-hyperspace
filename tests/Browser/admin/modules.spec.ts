import type { Page } from '@playwright/test';
import { company, riskCheckPackages } from '../support/test-data';
import { expect, test } from '../support/test';

const administration = (page: Page) =>
    page
        .locator('[data-sidebar="group"]')
        .filter({ hasText: 'Administration' });

test('the administration menu has both modules’ settings, named apart', async ({
    page,
}) => {
    await page.goto(`${company}/projects/dashboard`);

    await administration(page)
        .getByRole('link', { name: 'Project risk warnings' })
        .click();
    await expect(page).toHaveURL(new RegExp(`${company}/admin/risk-warnings$`));
    await expect(
        page.getByRole('heading', { name: 'Project risk warnings' }),
    ).toBeVisible();

    await administration(page)
        .getByRole('link', { name: 'Supplier settings' })
        .click();
    await expect(page).toHaveURL(new RegExp(`${company}/admin/settings$`));
    await expect(page.getByLabel('Reminder email')).toBeVisible();
});

test('the bell shows unread notifications and opens them inside the company, also from account pages', async ({
    page,
}) => {
    // The admin starts with three unread notifications about the reminded compressor; other tests may add more.
    await page.goto('/settings/profile');
    const bell = page.getByTestId('notification-bell');
    await expect(bell).toHaveAccessibleName(/^Notifications, \d+ unread$/);
    await expect(bell).toHaveAttribute('href', `${company}/notifications`);

    await bell.click();

    await expect(page).toHaveURL(new RegExp(`${company}/notifications$`));
    await expect(
        page
            .getByTestId('unread-notification')
            .filter({ hasText: riskCheckPackages.remindedCompressor.title })
            .first(),
    ).toBeVisible();
});
