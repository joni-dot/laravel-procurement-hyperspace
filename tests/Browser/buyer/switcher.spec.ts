import type { Page } from '@playwright/test';
import { company, otherCompany, seeded } from '../support/test-data';
import { expect, test } from '../support/test';

const firstCompanyName = 'Browser Test Company';

function switcher(page: Page) {
    return page.getByRole('button', { name: /switch company/ });
}

async function openSuppliersFromTheMenu(page: Page) {
    await page.getByRole('link', { name: 'Suppliers', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Suppliers', exact: true }),
    ).toBeVisible();
}

test('a buyer switches to another company and back without reloading the page', async ({
    page,
}) => {
    await page.goto(`${company}/suppliers`);
    await expect(page.getByText(seeded.supplierName)).toBeVisible();
    await page.evaluate(() => {
        document.documentElement.dataset.notReloaded = 'true';
    });

    await expect(switcher(page)).toContainText(firstCompanyName);
    await switcher(page).click();
    await page.getByRole('menuitem', { name: otherCompany.name }).click();

    await expect(page).toHaveURL(new RegExp(`${otherCompany.url}/dashboard$`));
    await expect(switcher(page)).toContainText(otherCompany.name);
    await openSuppliersFromTheMenu(page);
    await expect(page).toHaveURL(new RegExp(`${otherCompany.url}/suppliers`));
    await expect(page.getByText(otherCompany.supplierName)).toBeVisible();
    await expect(page.getByText(seeded.supplierName)).toHaveCount(0);

    // Back again by keyboard: the menu opens on the first company, which is the other one.
    await switcher(page).focus();
    await page.keyboard.press('Enter');
    await expect(
        page.getByRole('menuitem', { name: otherCompany.name }),
    ).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(
        page.getByRole('menuitem', { name: firstCompanyName }),
    ).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(new RegExp(`${company}/dashboard$`));
    await openSuppliersFromTheMenu(page);
    await expect(page.getByText(seeded.supplierName)).toBeVisible();
    await expect(page.getByText(otherCompany.supplierName)).toHaveCount(0);

    await expect(page.locator('html')).toHaveAttribute(
        'data-not-reloaded',
        'true',
    );
});

test('the switcher works with the menu collapsed to icons', async ({
    page,
}) => {
    await page.goto(`${company}/dashboard`);
    await page.getByRole('button', { name: 'Toggle sidebar' }).first().click();
    await expect(
        page.locator('[data-state="collapsed"]').first(),
    ).toBeVisible();

    await switcher(page).click();
    await expect(
        page.getByRole('menuitem', { name: otherCompany.name }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: 'Account settings' }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: /Invitations/ }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toHaveCount(0);
});
