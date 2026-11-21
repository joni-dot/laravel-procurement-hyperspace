import { company, dashboardProject, seeded } from '../support/test-data';
import { expect, test } from '../support/test';

// The member is in several projects, so they get both modules' sections, in this order.
const sectionKeys = [
    'ppr_next_actions',
    'ppr_projects',
    'srm_attention',
    'srm_upcoming',
] as const;

test('a project member gets both modules’ sections, which replace their placeholders', async ({
    page,
}) => {
    // Holds the request for the sections, which come after the page, so their placeholders can be seen.
    let release!: () => void;
    const released = new Promise<void>((resolve) => (release = resolve));
    await page.route(
        (url) => url.pathname === company,
        async (route) => {
            if (route.request().headers()['x-inertia-partial-data']) {
                await released;
            }

            await route.continue();
        },
    );

    await page.goto(company);
    await expect(
        page.getByRole('heading', { name: 'Home', exact: true }),
    ).toBeVisible();

    for (const key of sectionKeys) {
        await expect(page.getByTestId(`section-${key}-loading`)).toBeVisible();
    }

    release();

    for (const key of sectionKeys) {
        await expect(page.getByTestId(`section-${key}`)).toBeVisible();
    }
    await expect(page.locator('[data-test$="-loading"]')).toHaveCount(0);
    await expect(page.locator('section[data-test^="section-"]')).toHaveCount(
        sectionKeys.length,
    );
    for (const [index, key] of sectionKeys.entries()) {
        await expect(
            page.locator('section[data-test^="section-"]').nth(index),
        ).toHaveAttribute('data-test', `section-${key}`);
    }
});

test('the project sections show the same as the project dashboard', async ({
    page,
}) => {
    await page.goto(company);

    // Other tests may give the member packages elsewhere, so only this project's actions are compared.
    const nextActions = page.getByTestId('section-ppr_next_actions');
    const actions = nextActions
        .getByTestId('next-action')
        .filter({ hasText: dashboardProject.code });
    const [overdue, comingUp] = dashboardProject.memberActions;
    await expect(actions).toHaveCount(2);
    await expect(actions.nth(0)).toContainText(overdue.title);
    await expect(actions.nth(1)).toContainText(comingUp.title);

    const project = page
        .getByTestId('section-ppr_projects')
        .locator(`[data-project-code="${dashboardProject.code}"]`);
    const { tiles } = dashboardProject;
    await expect(project.getByTestId('tile-red')).toHaveText(
        new RegExp(`^\\s*${tiles.red}\\s*Red\\s*$`),
    );
    await expect(project.getByTestId('tile-due-soon')).toContainText(
        `${tiles.dueSoon}`,
    );

    // A number opens the package list it counts, as on the dashboard.
    await project.getByTestId('tile-red').click();
    await expect(page).toHaveURL(
        new RegExp(
            `${company}/projects/${dashboardProject.id}/packages\\?risk=red$`,
        ),
    );
    await expect(
        page.getByText(`${tiles.red} of ${tiles.total} packages match`, {
            exact: true,
        }),
    ).toBeVisible();

    await page.goBack();
    await nextActions
        .getByTestId('section-dashboard-link')
        .getByText('Project dashboard')
        .click();
    await expect(page).toHaveURL(new RegExp(`${company}/projects/dashboard$`));
});

test('a viewer gets no contract tile and no contract dates', async ({
    page,
}) => {
    await page.goto(company);

    const attention = page.getByTestId('section-srm_attention');
    await expect(attention.getByTestId('supplier-tile')).toHaveCount(4);
    await expect(
        attention.locator('[data-tile="contracts_action_soon"]'),
    ).toHaveCount(0);
    await expect(
        page
            .getByTestId('section-srm_upcoming')
            .locator('[data-kind^="contract"]'),
    ).toHaveCount(0);
    await expect(page.getByText(seeded.contractTitle)).toHaveCount(0);
});

test('the sidebar Home link opens the Home page and is highlighted only there', async ({
    page,
}) => {
    await page.goto(`${company}/suppliers`);
    const home = page
        .locator('[data-sidebar="content"]')
        .getByRole('link', { name: 'Home', exact: true });
    await expect(home).not.toHaveAttribute('aria-current');

    await home.click();

    await expect(page).toHaveURL(new RegExp(`${company}$`));
    await expect(
        page.getByRole('heading', { name: 'Home', exact: true }),
    ).toBeVisible();
    await expect(home).toHaveAttribute('aria-current', 'page');
});
