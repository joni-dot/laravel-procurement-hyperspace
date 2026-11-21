import { company, sourcing } from '../support/test-data';
import { expect, test } from '../support/test';

test('a project viewer sees a package’s suppliers but changes nothing', async ({
    page,
}) => {
    await page.goto(`${company}/projects`);
    await page
        .getByRole('link', { name: sourcing.project, exact: true })
        .click();
    await page.getByRole('link', { name: 'Packages', exact: true }).click();

    // The blocked chosen supplier is the package's only risk.
    const listRow = page.getByRole('row', {
        name: new RegExp(sourcing.blockedPackage),
    });
    await expect(listRow).toContainText('Amber');
    await expect(listRow).toContainText(
        'The chosen supplier is high risk or archived',
    );

    await listRow.getByRole('link', { name: sourcing.blockedPackage }).click();
    await expect(page.getByRole('region', { name: 'Risk' })).toContainText(
        'The chosen supplier is high risk or archived',
    );

    const section = page.getByRole('region', { name: 'Suppliers' });
    await expect(section.getByTestId('chosen-supplier-warning')).toContainText(
        `The chosen supplier is high risk or archived: ${sourcing.blockedSupplier}`,
    );
    const chosenRow = section.getByRole('row', {
        name: new RegExp(sourcing.blockedSupplier),
    });
    await expect(chosenRow.getByTestId('chosen-badge')).toBeVisible();
    await expect(chosenRow).toContainText('Blocked');
    await expect(chosenRow).toContainText('High');
    await expect(chosenRow).toContainText(sourcing.blockedNote);
    await expect(section).toContainText(sourcing.askedSupplier);
    await expect(section).toContainText('Asked for a quote');

    await expect(section.getByLabel('Add supplier')).toHaveCount(0);
    await expect(section.getByRole('combobox')).toHaveCount(0);
    await expect(section.getByRole('button')).toHaveCount(0);
});

test('a supplier’s page lists only the packages of projects the viewer can see', async ({
    page,
}) => {
    await page.goto(`${company}/suppliers`);
    await page.getByLabel('Search suppliers').fill(sourcing.blockedSupplier);
    // The list filters itself after a pause in typing; a click before that would be overtaken.
    await expect(page.getByText('Showing 1–1 of 1')).toBeVisible();
    await page
        .getByRole('link', { name: sourcing.blockedSupplier, exact: true })
        .click();

    const packages = page.getByRole('region', { name: 'Project packages' });
    await expect(packages).toContainText(sourcing.blockedPackage);
    await expect(packages).toContainText(sourcing.project);
    await expect(packages).not.toContainText(sourcing.hiddenProject);
    await expect(packages).not.toContainText(sourcing.hiddenPackage);
});
