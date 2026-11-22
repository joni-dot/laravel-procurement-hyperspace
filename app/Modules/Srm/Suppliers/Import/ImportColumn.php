<?php

namespace App\Modules\Srm\Suppliers\Import;

/**
 * The columns a supplier import file may have. The value is the column's header in the file: always
 * English, so one file works whatever language the importer uses; the label is what the page shows.
 */
enum ImportColumn: string
{
    case Name = 'Name';
    case SupplierNumber = 'Supplier number';
    case Status = 'Status';
    case Country = 'Country';
    case BusinessId = 'Business ID';
    case VatNumber = 'VAT number';
    case StreetAddress = 'Street address';
    case PostalCode = 'Postal code';
    case City = 'City';
    case Website = 'Website';
    case Email = 'Email';
    case Phone = 'Phone';
    case PaymentTerms = 'Payment terms';
    case Currency = 'Currency';
    case DeliveryTerm = 'Delivery term';
    case DeliveryPlace = 'Delivery place';
    case Notes = 'Notes';
    case Categories = 'Categories';
    case ResponsibleBuyer = 'Responsible buyer';
    case ContactName = 'Contact name';
    case ContactEmail = 'Contact email';
    case ContactPhone = 'Contact phone';

    public function label(): string
    {
        return match ($this) {
            self::Name => __('Name'),
            self::SupplierNumber => __('Supplier number'),
            self::Status => __('Status'),
            self::Country => __('Country'),
            self::BusinessId => __('Business ID'),
            self::VatNumber => __('VAT number'),
            self::StreetAddress => __('Street address'),
            self::PostalCode => __('Postal code'),
            self::City => __('City'),
            self::Website => __('Website'),
            self::Email => __('Email'),
            self::Phone => __('Phone'),
            self::PaymentTerms => __('Payment terms'),
            self::Currency => __('Currency'),
            self::DeliveryTerm => __('Delivery term'),
            self::DeliveryPlace => __('Delivery place'),
            self::Notes => __('Notes'),
            self::Categories => __('Categories'),
            self::ResponsibleBuyer => __('Responsible buyer'),
            self::ContactName => __('Contact name'),
            self::ContactEmail => __('Contact email'),
            self::ContactPhone => __('Contact phone'),
        };
    }

    /** What the import page tells people to put in the column. */
    public function hint(): string
    {
        return match ($this) {
            self::Name => __('Required.'),
            self::Status => __('Prospect, active, inactive or blocked. Empty means prospect.'),
            self::Country => __('Required. Two-letter code such as FI, or the country name in English.'),
            self::BusinessId => __('Finnish business IDs are checked, for example 1234567-8.'),
            self::Website => __('https:// is added when missing.'),
            self::Currency => __('Three-letter code such as EUR.'),
            self::DeliveryTerm => __('Incoterms code such as DAP.'),
            self::Categories => __('Category names separated by semicolons (;).'),
            self::ResponsibleBuyer => __('Email of an active buyer or admin of this company.'),
            self::ContactName => __('Becomes the primary contact, with the contact email and phone.'),
            default => '',
        };
    }

    public function isRequired(): bool
    {
        return $this === self::Name || $this === self::Country;
    }

    /** The supplier field the column fills, or null for columns that aren't supplier fields. */
    public function supplierField(): ?string
    {
        return match ($this) {
            self::Name => 'name',
            self::SupplierNumber => 'supplier_number',
            self::Status => 'status',
            self::Country => 'country_code',
            self::BusinessId => 'business_id',
            self::VatNumber => 'vat_number',
            self::StreetAddress => 'street_address',
            self::PostalCode => 'postal_code',
            self::City => 'city',
            self::Website => 'website',
            self::Email => 'email',
            self::Phone => 'phone',
            self::PaymentTerms => 'payment_terms',
            self::Currency => 'currency_code',
            self::DeliveryTerm => 'delivery_term',
            self::DeliveryPlace => 'delivery_place',
            self::Notes => 'notes',
            self::ResponsibleBuyer => 'responsible_user_id',
            default => null,
        };
    }

    public static function forSupplierField(string $field): ?self
    {
        foreach (self::cases() as $column) {
            if ($column->supplierField() === $field) {
                return $column;
            }
        }

        return null;
    }

    /**
     * The headers, in the order the template lists them.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return array_map(fn (self $column) => $column->value, self::cases());
    }
}
