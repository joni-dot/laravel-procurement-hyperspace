import type { Page } from '@playwright/test';
import { asManager } from '../support/manager';
import { company, sourcing } from '../support/test-data';
import { expect, test } from '../support/test';

async function openPackage(page: Page, title: string): Promise<void> {
    await page.goto(`${company}/projects`);
    await page
        .getByRole('link', { name: sourcing.project, exact: true })
        .click();
    await page.getByRole('link', { name: 'Packages', exact: true }).click();
    await page.getByRole('link', { name: title }).click();
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
}

test('the manager adds suppliers to a package, chooses one and finds the package on its page', async ({
    browser,
    baseURL,
}) => {
    await asManager(browser, baseURL, async (page) => {
        await openPackage(page, sourcing.openPackage);
        const section = page.getByRole('region', { name: 'Suppliers' });
        await expect(section).toContainText(
            'No suppliers have been asked for a quote yet.',
        );

        // Archived and blocked suppliers can't be added, so the search leaves them out.
        const search = section.getByLabel('Add supplier');
        await search.fill('Sourcing Blocked');
        await expect(section).toContainText('No supplier matches.');

        for (const name of [sourcing.offerSupplier, sourcing.spareSupplier]) {
            await search.fill(name);
            await section.getByRole('button', { name: `Add ${name}` }).click();
            await expect(
                section.getByRole('link', { name, exact: true }),
            ).toBeVisible();
            await expect(search).toHaveValue('');
        }

        await section
            .getByLabel(`Quote status of ${sourcing.offerSupplier}`)
            .selectOption({ label: 'Chosen' });
        await expect(section.getByTestId('chosen-badge')).toHaveCount(1);
        const offerRow = section.getByRole('row', {
            name: new RegExp(sourcing.offerSupplier),
        });
        await expect(offerRow.getByTestId('chosen-badge')).toBeVisible();

        await section
            .getByRole('button', {
                name: `Edit the note about ${sourcing.offerSupplier}`,
            })
            .click();
        await section
            .getByRole('textbox', {
                name: `Note about ${sourcing.offerSupplier}`,
            })
            .fill('Best price and delivery');
        await section.getByRole('button', { name: 'Save note' }).click();
        await expect(offerRow).toContainText('Best price and delivery');

        await section
            .getByRole('button', { name: `Remove ${sourcing.spareSupplier}` })
            .click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Remove' })
            .click();
        await expect(
            section.getByRole('link', { name: sourcing.spareSupplier }),
        ).toHaveCount(0);

        await section
            .getByRole('link', { name: sourcing.offerSupplier, exact: true })
            .click();
        const packages = page.getByRole('region', {
            name: 'Project packages',
        });
        const packageRow = packages.getByRole('row', {
            name: new RegExp(sourcing.openPackage),
        });
        await expect(packageRow).toContainText(sourcing.project);
        await expect(packageRow).toContainText('Chosen');

        await packageRow
            .getByRole('link', { name: sourcing.openPackage })
            .click();
        await expect(
            page.getByRole('heading', { name: sourcing.openPackage }),
        ).toBeVisible();
    });
});
