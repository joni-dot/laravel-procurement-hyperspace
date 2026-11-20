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
