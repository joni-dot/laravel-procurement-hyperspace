import { company, password, platformAdmin, users } from './support/test-data';
import { expect, test } from './support/test';
import { twoFactorCode } from './support/totp';

// Signs in once per role, so the other tests can start signed in. The addresses
// exist only in the test data, so this also fails if the app is on another database.
for (const [role, email] of Object.entries(users) as [
    keyof typeof users,
    string,
][]) {
    test(`sign in as ${role}`, async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByTestId('login-button').click();

        // Everyone lands on the company's Home page.
        await expect(page).toHaveURL(new RegExp(`${company}$`));

        await page
            .context()
            .storageState({ path: `tests/Browser/.auth/${role}.json` });
    });
}

// Platform admins must use two-factor sign-in, so this one also enters the code.
test('sign in as platform admin', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(platformAdmin.email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByTestId('login-button').click();

    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.keyboard.type(twoFactorCode(platformAdmin.twoFactorSecret));
    await page.getByRole('button', { name: 'Continue' }).click();

    // Platform pages ask for the password again unless it was entered recently, like the API
    // clients page; signing in doesn't count as entering it.
    await expect(
        page.getByRole('heading', { name: 'Confirm password' }),
    ).toBeVisible();
    await page.getByRole('textbox', { name: 'Password' }).fill(password);
    await page.getByRole('button', { name: 'Confirm password' }).click();

    await expect(page).toHaveURL(/\/platform\/companies$/);

    await page
        .context()
        .storageState({ path: 'tests/Browser/.auth/platform.json' });
});
