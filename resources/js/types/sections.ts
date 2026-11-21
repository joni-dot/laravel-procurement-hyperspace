/** A section a module adds to a page it doesn't own (App\Pages\PageSections). */
export type PageSection = {
    key: string;
    /** The component under resources/js/sections, such as "sourcing/PackageSuppliers". */
    component: string;
};
