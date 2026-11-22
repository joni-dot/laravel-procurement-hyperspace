import type { Page } from '@playwright/test';
import { onboardingCompany, password } from '../support/test-data';
import { expect, test } from '../support/test';

// The onboarding company's admin has no saved sign-in, and the company is this test's alone.
test.use({ storageState: { cookies: [], origins: [] } });

async function signInAsNewcomer(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(onboardingCompany.admin);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByTestId('login-button').click();
    await expect(page).toHaveURL(new RegExp(`${onboardingCompany.url}$`));
}

test('a new company’s admin ticks off a step, hides the checklist and shows it again', async ({
    page,
}) => {
    await signInAsNewcomer(page);

    const checklist = page.getByTestId('section-onboarding_checklist');
    const reminderStep = checklist.locator(
        '[data-step="choose_reminder_email"]',
    );
    await expect(checklist).toBeVisible();
    await expect(checklist.getByTestId('onboarding-progress')).toHaveText(
        '0 of 5 done',
    );
    // It comes first, above the modules' sections.
    await expect(
        page.locator('[data-test^="section-"]').first(),
    ).toHaveAttribute('data-test', 'section-onboarding_checklist');

    await reminderStep
        .getByRole('link', { name: 'Choose where reminders go' })
        .click();
    await expect(page).toHaveURL(
        new RegExp(`${onboardingCompany.url}/admin/settings$`),
    );
    await page
        .getByLabel('Reminder email')
        .fill('newcomer-reminders@browser-test.example');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();

    await page.getByRole('link', { name: 'Home', exact: true }).first().click();
    await expect(page).toHaveURL(new RegExp(`${onboardingCompany.url}$`));
    await expect(reminderStep).toHaveAttribute('data-done', 'true');
    await expect(
        reminderStep.getByRole('link', { name: 'Choose where reminders go' }),
    ).toHaveCount(0);
    await expect(checklist.getByTestId('onboarding-progress')).toHaveText(
        '1 of 5 done',
    );

    await checklist.getByTestId('onboarding-hide').click();
    await expect(checklist).toHaveCount(0);
    const showAgain = page.getByTestId('onboarding-show');
    await expect(showAgain).toBeVisible();

    // Hidden for the whole company, so it stays hidden on the next visit.
    await page.reload();
    await expect(checklist).toHaveCount(0);

    await showAgain.click();
    await expect(checklist).toBeVisible();
    await expect(checklist.getByTestId('onboarding-progress')).toHaveText(
        '1 of 5 done',
    );
    await expect(showAgain).toHaveCount(0);
});
