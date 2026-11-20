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
