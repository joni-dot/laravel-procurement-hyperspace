<?php

namespace App\Modules\Srm\Demo;

use App\Companies\DemoRandom;
use App\Models\User;
use App\Modules\Srm\Actions\Contracts\SaveContract;
use App\Modules\Srm\Actions\Ratings\RecalculateOverallScores;
use App\Modules\Srm\Enums\ContractStatus;
use App\Modules\Srm\Enums\ContractValuePeriod;
use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Enums\DeliveryTerm;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\RatingCriterion;
use App\Modules\Srm\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Made-up suppliers for a demo company: about 150, mostly active, with a primary contact each, certificates,
 * ratings, spend and a few contracts, chosen so every tile on the supplier dashboard has something to show.
 * Runs inside the demo company. Rows are inserted in bulk, so a demo company is ready in about a second;
 * overall rating scores are still worked out in their one place, and contracts are saved through SaveContract.
 *
 * No reminders are scheduled, so nobody gets reminder emails about made-up certificates and contracts.
 */
final class DemoSuppliers
{
    private const int SEED = 150;

    private const array STATUS_SHARES = ['active' => 125, 'prospect' => 12, 'inactive' => 8, 'blocked' => 5];

    private const int ARCHIVED = 5;

    private const int MISSING_REQUIRED_CERTIFICATE = 8;

    private const int EXPIRED_REQUIRED_CERTIFICATE = 4;

    private const int EXPIRING_REQUIRED_CERTIFICATE = 8;

    private const int RATED = 70;

    /** Mostly good suppliers; the 2s make a few high risk. */
    private const array RATING_LEVELS = [5, 5, 4, 4, 4, 4, 3, 3, 2];

    private DemoRandom $random;

    private CarbonImmutable $today;

    public function __construct(
        private readonly RecalculateOverallScores $recalculateOverallScores,
        private readonly SaveContract $saveContract,
    ) {}

    public static function count(): int
    {
        return array_sum(DemoSupplierWords::COUNTRY_SHARES);
    }

    public function fill(User $admin): void
    {
        $this->random = new DemoRandom(self::SEED);
        $this->today = CarbonImmutable::today();

        $suppliers = $this->addSuppliers($admin, $this->categoryTrades());
        $this->addPrimaryContacts($suppliers);

        $current = array_values(array_filter($suppliers, fn (Supplier $supplier) => $supplier->status === SupplierStatus::Active && ! $supplier->isArchived()));
        $this->addCertificates($suppliers, $current);
        $this->addRatingsAndSpend($current, $admin);
        $this->addContracts($current, $admin);
    }

    /**
     * The company's own categories if it has some, otherwise the demo ones; each with the trade a supplier
     * in it puts in its name.
     *
     * @return array<int, string> per category id
     */
    private function categoryTrades(): array
    {
        $existing = Category::query()->orderBy('id')->pluck('name', 'id')->all();

        if ($existing !== []) {
            return array_map(fn (string $name) => DemoSupplierWords::CATEGORIES[$name] ?? $this->random->pick(DemoSupplierWords::GENERIC_TRADES), $existing);
        }

        $trades = [];

        foreach (DemoSupplierWords::CATEGORIES as $name => $trade) {
            $trades[Category::query()->create(['name' => $name])->id] = $trade;
        }

        return $trades;
    }

    /**
     * Inserted in one statement, then read back for their ids.
     *
     * @param  array<int, string>  $tradesByCategoryId
     * @return list<Supplier>
     */
    private function addSuppliers(User $admin, array $tradesByCategoryId): array
    {
        $countries = $this->random->shuffledShares(DemoSupplierWords::COUNTRY_SHARES);
        $statuses = $this->random->shuffledShares(self::STATUS_SHARES);
        $names = $this->random->some(self::nameStarts(), count($countries));
        $archived = $this->archivedPlaces($statuses);
        $businessIds = new DemoBusinessIds($this->random);
        $categoryIdsByNumber = [];
        $rows = [];

        foreach ($countries as $index => $country) {
            $number = 'S-'.(10001 + $index);
            $categoryIdsByNumber[$number] = $this->random->some(array_keys($tradesByCategoryId), $this->random->int(1, 2));
            $city = $this->random->pick(DemoSupplierWords::CITIES[$country]);

            $rows[] = [
                'name' => "{$names[$index]} {$tradesByCategoryId[$categoryIdsByNumber[$number][0]]} ".DemoSupplierWords::LEGAL_FORMS[$country],
                'supplier_number' => $number,
                'status' => $statuses[$index],
                'responsible_user_id' => $this->random->chance(60) ? $admin->id : null,
                'country_code' => $country,
                ...$businessIds->next($country),
                'street_address' => $this->random->pick(DemoSupplierWords::STREETS[$country]).' '.$this->random->int(1, 120),
                'postal_code' => $this->postalCode($country),
                'city' => $city,
                'email' => Str::lower($names[$index]).'@example.com',
                'payment_terms' => $this->random->pick(DemoSupplierWords::PAYMENT_TERMS),
                'currency_code' => DemoSupplierWords::CURRENCIES[$country],
                'delivery_term' => $this->random->pick([DeliveryTerm::Dap, DeliveryTerm::Fca, DeliveryTerm::Exw, DeliveryTerm::Ddp])->value,
                'delivery_place' => $city,
                'archived_at' => isset($archived[$index]) ? $this->today->subDays($this->random->int(30, 400)) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Plain inserts: the database fills in the open company.
        DB::table('suppliers')->insert($rows);
        $suppliers = array_values(Supplier::query()->whereIn('supplier_number', array_keys($categoryIdsByNumber))->orderBy('id')->get()->all());
        $this->linkCategories($suppliers, $categoryIdsByNumber);

        return $suppliers;
    }

    /**
     * A few active and inactive suppliers are archived, by their place in the list.
     *
     * @param  list<string>  $statuses
     * @return array<int, true>
     */
    private function archivedPlaces(array $statuses): array
    {
        $archivable = array_keys(array_filter($statuses, fn (string $status) => in_array($status, [SupplierStatus::Active->value, SupplierStatus::Inactive->value], true)));

        return array_fill_keys($this->random->some($archivable, self::ARCHIVED), true);
    }

    /**
     * @param  list<Supplier>  $suppliers
     * @param  array<string, list<int>>  $categoryIdsByNumber
     */
    private function linkCategories(array $suppliers, array $categoryIdsByNumber): void
    {
        $links = [];

        foreach ($suppliers as $supplier) {
            foreach ($categoryIdsByNumber[$supplier->supplier_number] as $categoryId) {
                $links[] = ['supplier_id' => $supplier->id, 'category_id' => $categoryId];
            }
        }

        DB::table('category_supplier')->insert($links);
    }

    /**
     * @param  list<Supplier>  $suppliers
     */
    private function addPrimaryContacts(array $suppliers): void
    {
        $now = now();
        $rows = [];

        foreach ($suppliers as $supplier) {
            $firstName = $this->random->pick(DemoSupplierWords::FIRST_NAMES[$supplier->country_code]);
            $lastName = $this->random->pick(DemoSupplierWords::LAST_NAMES[$supplier->country_code]);

            $rows[] = [
                'supplier_id' => $supplier->id,
                'name' => "{$firstName} {$lastName}",
                'job_title' => $this->random->pick(DemoSupplierWords::JOB_TITLES),
                'email' => Str::lower(Str::ascii("{$firstName}.{$lastName}")).'@example.org',
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('contacts')->insert($rows);
    }

    /**
     * Most active suppliers have a valid required certificate; a few have none, an expired one or one about to
     * expire, so the certificate tiles and the high-risk tile have something to show. Other certificates are
     * scattered over every supplier.
     *
     * @param  list<Supplier>  $suppliers
     * @param  list<Supplier>  $current  active, not archived
     */
    private function addCertificates(array $suppliers, array $current): void
    {
        $types = $this->certificateTypes();
        $required = $types[DemoSupplierWords::REQUIRED_CERTIFICATE];
        $rows = [];

        foreach ($this->random->shuffle($current) as $index => $supplier) {
            $expiresOn = match (true) {
                $index < self::MISSING_REQUIRED_CERTIFICATE => null,
                $index < self::MISSING_REQUIRED_CERTIFICATE + self::EXPIRED_REQUIRED_CERTIFICATE => $this->today->subDays($this->random->int(5, 120)),
                $index < self::MISSING_REQUIRED_CERTIFICATE + self::EXPIRED_REQUIRED_CERTIFICATE + self::EXPIRING_REQUIRED_CERTIFICATE => $this->today->addDays($this->random->int(3, 27)),
                default => $this->today->addDays($this->random->int(60, 900)),
            };

            if ($expiresOn !== null) {
                $rows[] = $this->certificateRow($supplier, $required, $expiresOn);
            }
        }

        foreach ($suppliers as $supplier) {
            foreach ([['ISO 9001', 45], ['ISO 14001', 25], ['Liability insurance', 30]] as [$name, $percent]) {
                if ($this->random->chance($percent)) {
                    $rows[] = $this->certificateRow($supplier, $types[$name], $this->otherCertificateExpiry());
                }
            }
        }

        DB::table('certificates')->insert($rows);
    }

    /**
     * @return array<string, CertificateType> per name
     */
    private function certificateTypes(): array
    {
        $types = [];

        foreach (DemoSupplierWords::CERTIFICATE_TYPES as $name => $isRequired) {
            $types[$name] = CertificateType::query()->firstOrCreate(['name' => $name], ['is_required' => $isRequired]);
        }

        return $types;
    }

    private function otherCertificateExpiry(): CarbonImmutable
    {
        return match (true) {
            $this->random->chance(5) => $this->today->subDays($this->random->int(5, 90)),
            $this->random->chance(10) => $this->today->addDays($this->random->int(3, 27)),
            default => $this->today->addDays($this->random->int(60, 1000)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateRow(Supplier $supplier, CertificateType $type, CarbonImmutable $expiresOn): array
    {
        return [
            'supplier_id' => $supplier->id,
            'certificate_type_id' => $type->id,
            'reference' => 'CERT-'.$this->random->int(10000, 99999),
            'issued_on' => $expiresOn->subYears(3)->toDateString(),
            'expires_on' => $expiresOn->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Ratings with their scores, then every overall score worked out in its one place, RecalculateOverallScores.
     * A few ratings are older than a year, and some suppliers have an earlier rating too, for a history.
     *
     * @param  list<Supplier>  $current
     */
    private function addRatingsAndSpend(array $current, User $admin): void
    {
        $criterionIds = array_values(RatingCriterion::query()->inUse()->orderBy('id')->get(['id'])->map(fn (RatingCriterion $criterion) => $criterion->id)->all());
        $rated = $this->random->some($current, self::RATED);

        if ($criterionIds !== []) {
            $scores = [];

            foreach ($rated as $supplier) {
                $ratedOn = $this->random->chance(85) ? $this->today->subDays($this->random->int(5, 330)) : $this->today->subDays($this->random->int(380, 700));

                if ($this->random->chance(20)) {
                    array_push($scores, ...$this->rate($supplier, $admin, $ratedOn->subDays($this->random->int(300, 400)), $criterionIds));
                }

                array_push($scores, ...$this->rate($supplier, $admin, $ratedOn, $criterionIds));
            }

            DB::table('rating_scores')->insert($scores);
            $this->recalculateOverallScores->handle();
        }

        $this->addSpend($rated);
    }

    /**
     * Adds a rating, whose overall score is worked out afterwards, and returns its scores to insert.
     *
     * @param  list<int>  $criterionIds
     * @return list<array{supplier_rating_id: int, rating_criterion_id: int, score: int}>
     */
    private function rate(Supplier $supplier, User $admin, CarbonImmutable $ratedOn, array $criterionIds): array
    {
        $ratingId = DB::table('supplier_ratings')->insertGetId([
            'supplier_id' => $supplier->id,
            'rated_by_user_id' => $admin->id,
            'rated_on' => $ratedOn->toDateString(),
            'overall_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $level = $this->random->pick(self::RATING_LEVELS);

        return array_map(fn (int $criterionId) => [
            'supplier_rating_id' => $ratingId,
            'rating_criterion_id' => $criterionId,
            'score' => max(1, min(5, $level + $this->random->int(-1, 1))),
        ], $criterionIds);
    }

    /**
     * @param  list<Supplier>  $suppliers
     */
    private function addSpend(array $suppliers): void
    {
        $rows = [];

        foreach ($suppliers as $supplier) {
            foreach ([$this->today->year - 1, $this->today->year] as $year) {
                $rows[] = [
                    'supplier_id' => $supplier->id,
                    'year' => $year,
                    'amount' => $this->random->int(50, 9000) * 100,
                    'currency_code' => Currency::Eur->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('supplier_spends')->insert($rows);
    }

    /**
     * A handful of contracts: some needing action within a month, one past its end, the rest running, one
     * draft and one terminated. Saved through SaveContract, without reminders.
     *
     * @param  list<Supplier>  $current
     */
    private function addContracts(array $current, User $admin): void
    {
        $typeIds = ContractType::query()->orderBy('id')->pluck('id')->all();
        $plans = $this->contractPlans();

        if ($typeIds === []) {
            return;
        }

        foreach ($this->random->some($current, count($plans)) as $index => $supplier) {
            [$status, $endsInDays, $noticeDays] = $plans[$index];
            $endsOn = $endsInDays === null ? null : $this->today->addDays($endsInDays);

            $this->saveContract->handle($supplier->contracts()->make(), [
                'owner_user_id' => $admin->id,
                'title' => DemoSupplierWords::CONTRACT_TITLES[$index % count(DemoSupplierWords::CONTRACT_TITLES)],
                'contract_number' => sprintf('C-%d-%03d', $this->today->year, $index + 1),
                'contract_type_id' => $this->random->pick($typeIds),
                'status' => $status,
                'starts_on' => $this->today->subDays($this->random->int(200, 900)),
                'ends_on' => $endsOn,
                'auto_renews' => $noticeDays !== null,
                'renewal_term_months' => $noticeDays !== null ? 12 : null,
                'notice_period_days' => $noticeDays,
                'value' => $this->random->int(10, 400) * 1000,
                'currency_code' => Currency::Eur,
                'value_period' => $this->random->pick([ContractValuePeriod::Yearly, ContractValuePeriod::Total]),
            ], []);
        }
    }

    /**
     * Status, days until the end (null: no end date) and notice days of an automatic renewal (null: none).
     * A renewal's notice is due its notice days before the end.
     *
     * @return list<array{ContractStatus, int|null, int|null}>
     */
    private function contractPlans(): array
    {
        return [
            [ContractStatus::Active, 12, null],
            [ContractStatus::Active, 25, null],
            [ContractStatus::Active, 100, 90],
            [ContractStatus::Active, 110, 90],
            [ContractStatus::Active, -20, null],
            [ContractStatus::Active, 400, null],
            [ContractStatus::Active, 520, null],
            [ContractStatus::Active, 640, null],
            [ContractStatus::Active, 250, 60],
            [ContractStatus::Active, 300, 60],
            [ContractStatus::Active, null, null],
            [ContractStatus::Active, null, null],
            [ContractStatus::Draft, 365, null],
            [ContractStatus::Terminated, 200, null],
        ];
    }

    private function postalCode(string $country): string
    {
        return match ($country) {
            'SE' => $this->random->int(100, 999).' '.$this->random->int(10, 99),
            'NL' => $this->random->int(1000, 9999).' '.$this->random->pick(['AB', 'CD', 'EK', 'GT']),
            'PL' => $this->random->int(10, 99).'-'.$this->random->int(100, 999),
            default => sprintf('%05d', $this->random->int(1000, 99999)),
        };
    }

    /**
     * Every two-word start of a name, such as "Puffinmoor".
     *
     * @return list<string>
     */
    private static function nameStarts(): array
    {
        $names = [];

        foreach (DemoSupplierWords::NAME_STARTS as $start) {
            foreach (DemoSupplierWords::NAME_ENDS as $end) {
                $names[] = $start.$end;
            }
        }

        return $names;
    }
}
