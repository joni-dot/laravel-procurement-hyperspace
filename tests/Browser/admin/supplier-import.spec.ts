import { importCompany, password } from '../support/test-data';
import { expect, test } from '../support/test';

// The import company's admin has no saved sign-in: the test signs in itself, so its suppliers stay apart
// from the main browser test company's.
test.use({ storageState: { cookies: [], origins: [] } });

test('an admin checks a supplier file and imports it', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(importCompany.admin);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByTestId('login-button').click();
    await expect(page).toHaveURL(new RegExp(`${importCompany.url}$`));

    await page.goto(`${importCompany.url}/suppliers`);
    await page.getByRole('link', { name: 'Import', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Import suppliers' }),
    ).toBeVisible();

    await expect(
        page.getByRole('link', { name: 'Excel template' }),
    ).toHaveAttribute('href', /\/suppliers\/import\/template\?format=xlsx$/);
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'CSV template' }).click();
    expect((await download).suggestedFilename()).toBe(
        'suppliers-import-template.csv',
    );

    const file = {
        name: 'suppliers.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(
            [
                'Name;Supplier number;Country;City',
                'Browser Import Oy;BI-1;Finland;Tampere',
                'Browser Import Again;BI-1;FI;Turku',
                'Broken Row;BI-2;Atlantis;',
            ].join('\r\n'),
        ),
    };
    await page.getByLabel('File').setInputFiles(file);
    await page.getByRole('button', { name: 'Check file' }).click();
    await expect(page.getByTestId('count-add')).toHaveText('1');

    // Reloading the page after the check loses only the chosen file.
    await page.reload();
    await expect(
        page.getByRole('heading', { name: 'Import suppliers' }),
    ).toBeVisible();
    await expect(page.getByTestId('count-add')).toHaveCount(0);

    await page.getByLabel('File').setInputFiles(file);
    await page.getByRole('button', { name: 'Check file' }).click();

    await expect(page.getByTestId('count-add')).toHaveText('1');
    await expect(page.getByTestId('count-skipped')).toHaveText('1');
    await expect(page.getByTestId('count-errors')).toHaveText('1');
    await expect(page.getByTestId('problem-skipped')).toContainText(
        'Same as row 2',
    );
    await expect(page.getByTestId('problem-error')).toContainText(
        'The selected country is invalid.',
    );

    await page.getByRole('button', { name: 'Import', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`${importCompany.url}/suppliers$`));
    await expect(
        page.getByText(
            '1 supplier added. 1 row skipped. 1 row with errors left out.',
        ),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Browser Import Oy' }),
    ).toBeVisible();
    await expect(page.getByText('Broken Row')).toHaveCount(0);
});
