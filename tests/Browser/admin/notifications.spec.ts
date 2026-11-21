import { shownDate } from '../support/dates';
import {
    company,
    riskCheckPackages,
    seeded,
    supplierNotification,
} from '../support/test-data';
import { expect, test } from '../support/test';

/**
 * A date this many days from today (UTC, as the server counts) as "YYYY-MM-DD".
 */
function daysFromToday(days: number): string {
    return new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
}

test('one notifications page lists project and supplier notifications, and opening one goes to its page', async ({
    page,
}) => {
    const expires = await shownDate(
        page,
        daysFromToday(supplierNotification.expiresInDays),
    );
    const supplierMessage = `${supplierNotification.supplierName}: the ${supplierNotification.certificateType} certificate expires on ${expires}.`;

    await page.goto(`${company}/notifications`);
    const unread = page.getByTestId('unread-notification');
    await expect(
        unread
            .filter({ hasText: riskCheckPackages.remindedCompressor.title })
            .first(),
    ).toBeVisible();
    const supplierRow = unread.filter({ hasText: supplierMessage });
    await expect(supplierRow).toHaveCount(1);
    await expect(supplierRow).toContainText('Suppliers');

    await supplierRow.getByRole('link', { name: supplierMessage }).click();
    await expect(page).toHaveURL(new RegExp(`${seeded.supplierUrl}$`));
    await expect(
        page.getByRole('heading', { name: seeded.supplierName }),
    ).toBeVisible();

    await page.goto(`${company}/notifications`);
    await expect(
        page
            .getByTestId('read-notification')
            .getByRole('link', { name: supplierMessage }),
    ).toBeVisible();
});

test('a person chooses whether their notifications also come by email', async ({
    page,
}) => {
    await page.goto('/settings/profile');
    await page
        .getByRole('navigation', { name: 'Settings' })
        .getByRole('link', { name: 'Notifications' })
        .click();

    const email = page.getByLabel('Notifications by email');
    await expect(email).toHaveValue('daily');
    // The projects' reminder period is a section of the same page.
    await expect(
        page.getByLabel('Remind me this many days before a date'),
    ).toBeVisible();

    await email.selectOption({ label: 'Off' });
    await page.getByRole('button', { name: 'Save email choice' }).click();
    await expect(page.getByText('Notification settings saved.')).toBeVisible();

    await page.reload();
    await expect(email).toHaveValue('off');
});
