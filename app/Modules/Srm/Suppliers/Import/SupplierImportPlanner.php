<?php

namespace App\Modules\Srm\Suppliers\Import;

use App\Modules\Srm\Suppliers\ContactFields;
use App\Modules\Srm\Suppliers\SupplierFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Reads an import file and decides, row by row, what importing it would do. Saves nothing: the preview
 * shows the plan, and the import makes the plan again from the same file before saving it.
 */
final class SupplierImportPlanner
{
    public const int MAX_ROWS = 5_000;

    /** More new categories than any company starts with; beyond it, the Categories column is likely wrong. */
    public const int MAX_NEW_CATEGORIES = 100;

    private const array CONTACT_COLUMNS = [
        'name' => ImportColumn::ContactName,
        'email' => ImportColumn::ContactEmail,
        'phone' => ImportColumn::ContactPhone,
    ];

    private ImportLookups $lookups;

    private bool $mayCreateCategories;

    /** @var list<PlannedSupplier> */
    private array $suppliers = [];

    /** @var list<ImportProblem> */
    private array $problems = [];

    private int $skippedRows = 0;

    private int $errorRows = 0;

    /** @var array<string, string> lowercased name => name as first written */
    private array $newCategories = [];

    /** @var array<string, int> a key of a supplier the file adds => its row */
    private array $rowsByKey = [];

    private FieldChecker $supplierChecker;

    private FieldChecker $contactChecker;

    public function __construct(private readonly SpreadsheetRows $spreadsheet) {}

    /**
     * @throws ImportFileRefused when the file as a whole can't be imported
     */
    public function plan(UploadedFile $file, bool $mayCreateCategories): SupplierImportPlan
    {
        [$header, $rows] = $this->readRows($file);

        $this->start($rows, $mayCreateCategories);

        foreach ($rows as $rowNumber => $values) {
            $this->planRow($rowNumber, $values);
        }

        return new SupplierImportPlan(
            $this->suppliers,
            $this->problems,
            $this->skippedRows,
            $this->errorRows,
            array_values($this->newCategories),
            $header->ignored,
        );
    }

    /**
     * @return array{ImportHeader, array<int, array<string, string>>} the header, and each row's values by column
     */
    private function readRows(UploadedFile $file): array
    {
        $header = null;
        $rows = [];

        foreach ($this->spreadsheet->read($file) as $rowNumber => $cells) {
            if ($header === null) {
                $header = ImportHeader::read($cells);

                continue;
            }

            if (count($rows) === self::MAX_ROWS) {
                throw new ImportFileRefused(__('The file has more than :max suppliers. Split it into smaller files.', ['max' => number_format(self::MAX_ROWS)]));
            }

            // Empty cells become null, like the form's empty fields.
            $rows[$rowNumber] = array_map(fn (string $value) => $value === '' ? null : $value, $header->values($cells));
        }

        return [$header ?? throw new ImportFileRefused(__('The file is empty.')), $rows];
    }

    /**
     * @param  array<int, array<string, string|null>>  $rows
     */
    private function start(array $rows, bool $mayCreateCategories): void
    {
        // Only empty values are left out: "0" is a supplier number like any other.
        $isGiven = fn (?string $value) => $value !== null && $value !== '';
        $supplierNumbers = array_values(array_filter(array_column($rows, ImportColumn::SupplierNumber->value), $isGiven));
        $businessIds = array_values(array_filter(array_map(
            fn (array $values) => SupplierFields::tidy(['business_id' => $values[ImportColumn::BusinessId->value] ?? null])['business_id'],
            $rows,
        ), $isGiven));

        $this->lookups = ImportLookups::load($supplierNumbers, $businessIds);
        $this->mayCreateCategories = $mayCreateCategories;
        $this->suppliers = $this->problems = $this->newCategories = $this->rowsByKey = [];
        $this->skippedRows = $this->errorRows = 0;
        $this->supplierChecker = new FieldChecker(SupplierFields::rules(), SupplierFields::messages(), SupplierFields::attributes());
        $this->contactChecker = new FieldChecker(ContactFields::rules(), ContactFields::messages());
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function planRow(int $row, array $values): void
    {
        $input = $this->supplierInput($values);

        if ($this->skipExisting($row, $input)) {
            return;
        }

        $warnings = [];
        $input['responsible_user_id'] = $this->responsibleBuyer($row, $values[ImportColumn::ResponsibleBuyer->value] ?? null, $warnings);
        $supplier = $this->checkSupplier($row, $input);
        $contact = $this->checkContact($row, $values, $warnings);

        if ($supplier === null || $contact === false) {
            $this->errorRows++;

            return;
        }

        if ($this->skipRepeat($row, $input, $values)) {
            return;
        }

        [$categoryIds, $newCategories] = $this->categories($row, $values[ImportColumn::Categories->value] ?? null, $warnings);

        array_push($this->problems, ...$warnings);
        $this->suppliers[] = new PlannedSupplier($row, $supplier, $categoryIds, $newCategories, $contact);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function skipExisting(int $row, array $input): bool
    {
        $number = $input['supplier_number'] ?? null;
        $businessId = $input['business_id'] ?? null;

        if ($number !== null && isset($this->lookups->suppliersByNumber[$number])) {
            return $this->skip($row, ImportColumn::SupplierNumber, __('Already exists: :name', ['name' => $this->lookups->suppliersByNumber[$number]]));
        }

        $businessIdKey = ImportLookups::businessIdKey((string) $input['country_code'], (string) $businessId);

        if ($businessId !== null && isset($this->lookups->suppliersByBusinessId[$businessIdKey])) {
            return $this->skip($row, ImportColumn::BusinessId, __('Already exists: :name', ['name' => $this->lookups->suppliersByBusinessId[$businessIdKey]]));
        }

        return false;
    }

    /**
     * A row the file already adds is skipped, and so is a later row with the same values. Rows with errors
     * aren't remembered: once fixed, the first of them is the one added.
     *
     * @param  array<string, mixed>  $input  the supplier's tidied fields
     * @param  array<string, string|null>  $values  the row as read
     */
    private function skipRepeat(int $row, array $input, array $values): bool
    {
        $keys = $this->repeatKeys($input, $values);

        foreach ($keys as $key => $column) {
            if (isset($this->rowsByKey[$key])) {
                return $this->skip($row, $column, __('Same as row :row', ['row' => $this->rowsByKey[$key]]));
            }
        }

        foreach (array_keys($keys) as $key) {
            $this->rowsByKey[$key] = $row;
        }

        return false;
    }

    /**
     * Rows people see as one supplier: same number, same country and business ID, or the very same values.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string|null>  $values
     * @return array<string, ImportColumn|null> key => the column that makes it, null for the whole row
     */
    private function repeatKeys(array $input, array $values): array
    {
        $number = $input['supplier_number'] ?? null;
        $businessId = $input['business_id'] ?? null;

        return array_filter([
            'number:'.$number => $number !== null ? ImportColumn::SupplierNumber : false,
            'business:'.ImportLookups::businessIdKey((string) $input['country_code'], (string) $businessId) => $businessId !== null ? ImportColumn::BusinessId : false,
            'values:'.md5(serialize($values)) => null,
        ], fn (ImportColumn|false|null $column) => $column !== false);
    }

    private function skip(int $row, ?ImportColumn $column, string $message): true
    {
        $this->skippedRows++;
        $this->problems[] = new ImportProblem($row, ProblemKind::Skipped, $column, $message);

        return true;
    }

    /**
     * Checks the supplier's fields with the supplier form's own rules.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null the supplier's attributes, or null when the row has errors
     */
    private function checkSupplier(int $row, array $input): ?array
    {
        $errors = $this->supplierChecker->errors($input);

        foreach ($errors as $field => $message) {
            $this->problems[] = new ImportProblem($row, ProblemKind::Error, ImportColumn::forSupplierField($field), $message);
        }

        return $errors === [] ? Arr::only($input, $this->supplierChecker->fields()) : null;
    }

    /**
     * The supplier's fields as the form would send them, tidied like the form's. Statuses and countries may
     * be written as people read them; an empty status gets the form's default.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    private function supplierInput(array $values): array
    {
        $input = [];

        foreach (ImportColumn::cases() as $column) {
            $field = $column->supplierField();

            if ($field !== null && $column !== ImportColumn::ResponsibleBuyer) {
                $input[$field] = $values[$column->value] ?? null;
            }
        }

        $status = $input['status'] ?? null;
        $country = $input['country_code'] ?? null;

        $input['status'] = $status === null ? SupplierFields::DEFAULT_STATUS->value : ($this->lookups->statuses[mb_strtolower($status)] ?? $status);
        $input['country_code'] = $country === null ? null : ($this->lookups->countries[mb_strtolower($country)] ?? $country);
        $input['currency_code'] = isset($input['currency_code']) ? Str::upper($input['currency_code']) : null;
        $input['delivery_term'] = isset($input['delivery_term']) ? Str::upper($input['delivery_term']) : null;

        return [...$input, ...SupplierFields::tidy($input)];
    }

    /**
     * @param  list<ImportProblem>  $warnings
     */
    private function responsibleBuyer(int $row, ?string $email, array &$warnings): ?int
    {
        if ($email === null) {
            return null;
        }

        $id = $this->lookups->buyers[mb_strtolower($email)] ?? null;

        if ($id === null) {
            $warnings[] = new ImportProblem($row, ProblemKind::Warning, ImportColumn::ResponsibleBuyer,
                __('No active buyer or admin has this email, so the responsible buyer is left empty.'));
        }

        return $id;
    }

    /**
     * The primary contact, checked with the contact form's rules. Only a contact with a name is added.
     *
     * @param  array<string, string|null>  $values
     * @param  list<ImportProblem>  $warnings
     * @return array{name: string, email: string|null, phone: string|null, is_primary: true}|false|null false when it has errors
     */
    private function checkContact(int $row, array $values, array &$warnings): array|false|null
    {
        $input = array_map(fn (ImportColumn $column) => $values[$column->value] ?? null, self::CONTACT_COLUMNS);

        if ($input['name'] === null) {
            if ($input['email'] !== null || $input['phone'] !== null) {
                $warnings[] = new ImportProblem($row, ProblemKind::Warning, ImportColumn::ContactName,
                    __('The contact is left off because it has no name.'));
            }

            return null;
        }

        $input = [...$input, 'is_primary' => true];
        $input = [...$input, ...ContactFields::tidy($input)];
        $errors = $this->contactChecker->errors($input);

        foreach ($errors as $field => $message) {
            $this->problems[] = new ImportProblem($row, ProblemKind::Error, self::CONTACT_COLUMNS[$field] ?? null, $message);
        }

        if ($errors !== []) {
            return false;
        }

        return ['name' => $input['name'], 'email' => $input['email'], 'phone' => $input['phone'], 'is_primary' => true];
    }

    /**
     * @param  list<ImportProblem>  $warnings
     * @return array{list<int>, list<string>} ids of existing categories, and names of categories to create
     */
    private function categories(int $row, ?string $cell, array &$warnings): array
    {
        $ids = [];
        $new = [];

        foreach ($this->categoryNames($cell) as $key => $name) {
            if (isset($this->lookups->categories[$key])) {
                $ids[] = $this->lookups->categories[$key];
            } elseif (! $this->mayCreateCategories) {
                $warnings[] = new ImportProblem($row, ProblemKind::Warning, ImportColumn::Categories,
                    __('There is no category ":name", so it is left off. An admin can add it.', ['name' => Str::limit($name, 100)]));
            } elseif (mb_strlen($name) > 100) {
                $warnings[] = new ImportProblem($row, ProblemKind::Warning, ImportColumn::Categories,
                    __('A category name may be at most 100 characters, so this one is left off.'));
            } else {
                if (! isset($this->newCategories[$key]) && count($this->newCategories) === self::MAX_NEW_CATEGORIES) {
                    throw new ImportFileRefused(__('The file names more than :max new categories. Check the Categories column, or add the categories first.', ['max' => self::MAX_NEW_CATEGORIES]));
                }

                $this->newCategories[$key] ??= $name;
                $new[] = $this->newCategories[$key];
            }
        }

        return [$ids, $new];
    }

    /**
     * @return array<string, string> lowercased name => name, without repeats
     */
    private function categoryNames(?string $cell): array
    {
        $names = [];

        foreach (explode(';', (string) $cell) as $name) {
            $name = Str::trim($name);

            if ($name !== '') {
                $names[mb_strtolower($name)] ??= $name;
            }
        }

        return $names;
    }
}
