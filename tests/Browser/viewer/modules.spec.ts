import type { Page } from '@playwright/test';
import { company } from '../support/test-data';
import { expect, test } from '../support/test';

const sidebar = (page: Page) => page.locator('[data-sidebar="content"]');

const sidebarLink = (page: Page, name: string) =>
    sidebar(page).getByRole('link', { name, exact: true });

/** Holds Inertia's requests for the path until the returned function is called. */
async function holdPageRequests(page: Page, path: string): Promise<() => void> {
    let release!: () => void;
    const released = new Promise<void>((resolve) => (release = resolve));

    await page.route(
        (url) => url.pathname === path,
        async (route) => {
            if (route.request().headers()['x-inertia']) {
                await released;
            }

            await route.continue();
        },
    );

    return release;
}

test('the menu shows both modules and each of their pages opens', async ({
    page,
}) => {
    await page.goto(`${company}/projects/dashboard`);
    await page.evaluate(() => {
        document.documentElement.dataset.notReloaded = 'true';
    });

    const pages = [
        {
            link: 'Project dashboard',
            path: '/projects/dashboard',
            heading: 'Project dashboard',
        },
        { link: 'Projects', path: '/projects', heading: 'Projects' },
        {
            link: 'Supplier dashboard',
            path: '/dashboard',
            heading: 'Needs attention',
        },
        { link: 'Suppliers', path: '/suppliers', heading: 'Suppliers' },
        // Home's address starts every other one, yet it is highlighted only on its own page.
        { link: 'Home', path: '', heading: 'Home' },
    ];

    for (const { link, path, heading } of pages) {
        await sidebarLink(page, link).click();

        await expect(page).toHaveURL(new RegExp(`${company}${path}$`));
        await expect(
            page.getByRole('heading', { name: heading, exact: true }),
        ).toBeVisible();
        // Only the page's own item is highlighted, even though the project dashboard's address
        // starts with the projects list's.
        await expect(sidebarLink(page, link)).toHaveAttribute(
            'aria-current',
            'page',
        );
        await expect(
            sidebar(page).locator('a[aria-current="page"]'),
        ).toHaveCount(1);
    }

    await expect(page.locator('html')).toHaveAttribute(
        'data-not-reloaded',
        'true',
    );
});

test('a slow supplier page shows the same loading cues as the project pages', async ({
    page,
}) => {
    await page.goto(`${company}/projects/dashboard`);
    const release = await holdPageRequests(page, `${company}/suppliers`);
    // Not exact: while loading, the spinner's "Loading" joins the link's name.
    const suppliers = sidebar(page).getByRole('link', { name: 'Suppliers' });
    const content = page.getByTestId('page-content');

    // Clicked without the pointer resting on the link first, so it isn't prefetched and really waits.
    await suppliers.dispatchEvent('click');

    await expect(content).toHaveAttribute('aria-busy', 'true');
    await expect(suppliers).toHaveAttribute('aria-current', 'page');
    await expect(
        suppliers.getByRole('status', { name: 'Loading' }),
    ).toBeVisible();

    release();

    await expect(
        page.getByRole('heading', { name: 'Suppliers', exact: true }),
    ).toBeVisible();
    await expect(content).not.toHaveAttribute('aria-busy');
    await expect(page.getByRole('status', { name: 'Loading' })).toHaveCount(0);
});

test('the company switcher opens the Home page', async ({ page }) => {
    // An account page, outside every company, so the switcher has somewhere to go.
    await page.goto('/settings/profile');

    await page.getByRole('button', { name: /switch company/ }).click();
    await page.getByRole('menuitem', { name: 'Browser Test Company' }).click();

    await expect(page).toHaveURL(new RegExp(`${company}$`));
    await expect(
        page.getByRole('heading', { name: 'Home', exact: true }),
    ).toBeVisible();
});
