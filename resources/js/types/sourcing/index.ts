import type { PackageStatus } from '@/types/ppr';
import type { RiskLevel, SupplierStatus } from '@/types/srm';

// See app/Modules/Sourcing/Enums/QuoteStatus.php.
export type QuoteStatus = 'asked' | 'offer_received' | 'declined' | 'chosen';

export type QuoteStatusOption = {
    value: QuoteStatus;
    label: string;
};

/** A supplier on a package: the section on the package page (App\Modules\Sourcing\Sections\PackageSuppliers). */
export type PackageCandidate = {
    id: number;
    status: QuoteStatus;
    statusLabel: string;
    note: string | null;
    supplier: {
        id: number;
        name: string;
        number: string | null;
        status: SupplierStatus;
        statusLabel: string;
        riskLevel: RiskLevel;
        isArchived: boolean;
    };
    /** Chosen, and high risk or archived: the package is amber for it. */
    putsPackageAtRisk: boolean;
};

export type SupplierMatch = {
    id: number;
    name: string;
    number: string | null;
};

export type PackageSuppliersSection = {
    projectId: number;
    packageId: number;
    candidates: PackageCandidate[];
    statuses: QuoteStatusOption[];
    canManage: boolean;
    /** Only after a search, and only for those who may manage the suppliers. */
    search: { term: string; matches: SupplierMatch[] } | null;
};

/** A package a supplier is on: the section on the supplier page (App\Modules\Sourcing\Sections\SupplierPackages). */
export type SupplierPackage = {
    projectId: number;
    projectName: string;
    projectCode: string;
    packageId: number;
    number: string;
    title: string;
    packageStatus: PackageStatus;
    status: QuoteStatus;
    statusLabel: string;
};

export type SupplierPackagesSection = {
    packages: SupplierPackage[];
    total: number;
};
