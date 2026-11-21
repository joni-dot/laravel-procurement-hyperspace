// Matches database/seeders/BrowserTestSeeder.php. The database is emptied before
// every run, so the first supplier and contract always have id 1.
export const password = 'password';

// Every company page lives under the company's address; the seeder creates this company.
export const company = '/browser-test';

export const users = {
    admin: 'admin@browser-test.example',
    buyer: 'buyer@browser-test.example',
    viewer: 'viewer@browser-test.example',
} as const;

// Platform admins must use two-factor sign-in; sign-in.setup.ts computes the code from this secret.
export const platformAdmin = {
    email: 'platform@browser-test.example',
    twoFactorSecret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
} as const;

// A second company of the buyer, for the company switcher test.
export const otherCompany = {
    name: 'Browser Other Company',
    url: '/browser-other',
    supplierName: 'Other Company Supplier',
} as const;

// A company that has invited the viewer, for the invitations test.
export const inviteCompany = {
    name: 'Browser Invite Company',
    url: '/browser-invite',
} as const;

export const seeded = {
    supplierUrl: `${company}/suppliers/1`,
    supplierName: 'Browser Test Supplier',
    contractUrl: `${company}/suppliers/1/contracts/1`,
    contractTitle: 'Confidential browser test contract',
    contractValue: '123456',
    spendAmount: '987654',
    requiredCertificate: 'ISO 9001',
    supplierComment: 'Browser test supplier comment',
    contractComment: 'Secret negotiation note',
    busySupplierUrl: `${company}/suppliers/2`,
    busySupplierCommentCount: 25,
} as const;

// ---- Projects module (BrowserTestProjects.php). Project ids are those inside the browser test company.
// The project tests' "member" is users.viewer: a company viewer whose rights come from project roles.

// A company viewer who is project manager everywhere. Has no saved sign-in; a test that needs them signs in itself.
export const manager = 'manager@browser-test.example';

export const projects = {
    // The member is a viewer here.
    visible: { id: 1, name: 'Browser Project', code: 'BP1' },
    // The member does not belong to this one.
    hidden: { id: 2, name: 'Hidden Project', code: 'HP2' },
    // For the package tests. The member is a viewer here too.
    packages: { id: 3, name: 'Package Project', code: 'PP3' },
    // For the milestone list tests, which change its milestones. The member is a viewer here.
    milestones: { id: 4, name: 'Milestone Project', code: 'MP4' },
    // For the notification tests. The member is a buyer here.
    notifications: { id: 5, name: 'Notification Project', code: 'NP5' },
    // For the risk tests. The member is a viewer here.
    risk: { id: 6, name: 'Risk Project', code: 'RP6' },
    // For the risk check tests. The member and the admin are package owners here.
    riskCheck: { id: 7, name: 'Risk Check Project', code: 'RC7' },
    // 300 packages, "Long list item 001" to "Long list item 300". The member is a viewer here.
    longList: {
        id: 8,
        name: 'Long List Project',
        code: 'LL8',
        packageCount: 300,
    },
} as const;

// Every number of the dashboard project, as ProjectAttention counts them. The member is a package owner
// here and owns "Overdue order" (Order placed 3 days ago) and "Coming delivery" (delivery in 5 days).
export const dashboardProject = {
    id: 9,
    name: 'Dashboard Project',
    code: 'DP9',
    tiles: {
        total: 6,
        open: 5,
        red: 2, // Overdue order, Flagged switchgear
        amber: 2, // Buyerless cable, Undated panel
        overdue: 1, // Overdue order
        dueSoon: 1, // Coming delivery
        missingPeople: 1, // Buyerless cable
        noNeedDate: 1, // Undated panel
        flagged: 1, // Flagged switchgear
    },
    memberActions: [
        {
            kind: 'overdue',
            title: 'Overdue order',
            milestone: 'Order placed',
            daysAgo: 3,
        },
        {
            kind: 'coming_up',
            title: 'Coming delivery',
            milestone: 'Delivered from supplier',
            daysLeft: 5,
        },
    ],
} as const;

// The packages of the risk check project. Both have plenty of time before their need date at the start.
export const riskCheckPackages = {
    // The member owns it.
    watchedTank: {
        number: 'RC7-0001',
        title: 'Watched tank',
        needDate: '2099-06-01',
        forecast: '2099-01-01',
    },
    // The admin owns it. Its order was expected two days before the database was filled, and its delivery
    // is expected three days after, so it is red and the admin starts with three notifications about it.
    remindedCompressor: {
        number: 'RC7-0002',
        title: 'Reminded compressor',
        orderDaysAgo: 2,
        deliveryInDays: 3,
    },
} as const;

// A cancelled package and its sub-package, which counts as finished because of it. Open them by title.
export const finishedParentProject = {
    id: 10,
    name: 'Finished Parent Project',
    code: 'FP10',
    cancelledParent: 'Cancelled frame',
    subPackage: 'Leftover bolts',
} as const;

// The packages of the risk project, in list order. "Overdue pump" is a sub-package of "Main skid".
export const riskPackages = {
    mainSkid: { id: 3, title: 'Main skid' },
    overduePump: { id: 4, title: 'Overdue pump', overdueOrder: '2020-01-15' },
    unownedCrate: { id: 5, title: 'Unowned crate' },
    calmValve: { id: 6, title: 'Calm valve' },
} as const;

// The only package at the start, in the package project.
export const seededPackage = {
    id: 1,
    number: 'PP3-MEC-0001',
    title: 'Seeded pump',
    // Its only milestone date at the start.
    installedForecast: '2027-09-30',
} as const;

// The first package of the notification project. Nobody owns or buys it.
export const notifiedPackage = {
    number: 'NP5-MEC-0001',
    title: 'Notified valve',
} as const;

// Eleven more packages of the notification project, "Summary pump 01" to "Summary pump 11", which the member owns
// and buys. Each has 151 days to spare; an amber warning above that turns them all amber at once.
export const summaryPackages = { count: 11, daysToSpare: 151 } as const;
