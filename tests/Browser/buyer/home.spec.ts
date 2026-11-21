import { company } from '../support/test-data';
import { expect, test } from '../support/test';

test('someone in no project gets only the supplier sections', async ({
    page,
}) => {
    await page.goto(company);

    await expect(page.getByTestId('section-srm_attention')).toBeVisible();
    await expect(page.getByTestId('section-srm_upcoming')).toBeVisible();
    await expect(page.locator('[data-test^="section-ppr_"]')).toHaveCount(0);
    // A buyer may see contract details, so the contract tile is there.
    await expect(
        page.locator('[data-tile="contracts_action_soon"]'),
    ).toBeVisible();
});

for (const tile of [
    'high_risk',
    'missing_certificates',
    'contracts_action_soon',
] as const) {
    test(`the ${tile} tile opens a supplier list of exactly that many`, async ({
        page,
    }) => {
        await page.goto(company);
        const link = page
            .getByTestId('section-srm_attention')
            .locator(`[data-tile="${tile}"]`);
        const count = Number(
            await link.getByTestId('supplier-tile-count').textContent(),
        );

        await link.click();

        await expect(page).toHaveURL(new RegExp(`${company}/suppliers\\?`));

        if (count === 0) {
            await expect(page.getByText(/Showing .* of \d+/)).toHaveCount(0);
        } else {
            await expect(
                page.getByText(new RegExp(`Showing 1–\\d+ of ${count}$`)),
            ).toBeVisible();
        }
    });
}

test('the supplier sections link to the supplier dashboard', async ({
    page,
}) => {
    await page.goto(company);

    await page
        .getByTestId('section-srm_upcoming')
        .getByRole('link', { name: 'Supplier dashboard' })
        .click();

    await expect(page).toHaveURL(new RegExp(`${company}/dashboard$`));
    await expect(
        page.getByRole('heading', { name: 'Needs attention' }),
    ).toBeVisible();
});
