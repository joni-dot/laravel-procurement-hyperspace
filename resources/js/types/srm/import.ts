export type SupplierImportColumn = {
    header: string;
    label: string;
    hint: string;
    required: boolean;
};

export type SupplierImportProblem = {
    row: number;
    kind: 'error' | 'skipped' | 'warning';
    column: string | null;
    message: string;
};

export type SupplierImportPreview = {
    fileName: string;
    counts: { add: number; skipped: number; errors: number };
    problems: SupplierImportProblem[];
    problemCount: number;
    newCategories: string[];
    ignoredColumns: string[];
    suppliers: {
        row: number;
        name: string;
        supplier_number: string | null;
        country: string;
        city: string | null;
        contact: string | null;
    }[];
};
