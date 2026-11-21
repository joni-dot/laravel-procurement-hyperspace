<?php

namespace App\Modules\Ppr\Notifications;

use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Risk\PackageRisk;
use App\Modules\Ppr\Risk\RiskLevel;
use App\Modules\Ppr\Risk\RiskReason;
use App\Notifications\NotificationMessage;

/**
 * "Risk is now Red (was Amber): Late; No buyer." The reasons and their details are kept as they were
 * when the level changed, so the message doesn't change later.
 */
class RiskLevelChanged extends PackageNotification
{
    private readonly string $from;

    private readonly string $to;

    /** @var list<string> */
    private readonly array $reasons;

    private readonly ?string $overdueDate;

    /**
     * @param  int|null  $floatDays  counting sub-packages, for the "late" reason
     * @param  string|null  $overdueMilestoneName  for the "overdue" reason
     */
    public function __construct(
        Package $package,
        RiskLevel $from,
        PackageRisk $risk,
        private readonly ?int $floatDays,
        private readonly ?string $overdueMilestoneName,
    ) {
        parent::__construct($package);
        $this->from = $from->value;
        $this->to = $risk->level->value;
        $this->reasons = array_map(fn (RiskReason $reason) => $reason->value, $risk->reasons);
        $this->overdueDate = $risk->overdueMilestone?->due();
    }

    public static function kind(): string
    {
        return 'risk_changed';
    }

    /**
     * @return array{from: string, to: string, reasons: list<string>, float_days: int|null, overdue_milestone: string|null, overdue_date: string|null}
     */
    protected function params(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'reasons' => $this->reasons,
            'float_days' => $this->floatDays,
            'overdue_milestone' => $this->overdueMilestoneName,
            'overdue_date' => $this->overdueDate,
        ];
    }

    public static function message(array $data, string $projectName): NotificationMessage
    {
        $params = $data['params'];
        $from = RiskReasonWords::level(RiskLevel::from((string) $params['from']));
        $to = RiskLevel::from((string) $params['to']);

        if ($to === RiskLevel::None) {
            return NotificationMessage::make(__(':package is no longer at risk (it was :from).'), [
                'package' => self::packageName($data),
                'from' => $from,
            ]);
        }

        $reasons = array_map(
            fn (string $reason) => RiskReasonWords::reason(RiskReason::from($reason), $params['float_days'] ?? null, $params['overdue_milestone'] ?? null, $params['overdue_date'] ?? null),
            (array) $params['reasons'],
        );

        return NotificationMessage::make(__(':package: risk is now :to (was :from): :reasons.'), [
            'package' => self::packageName($data),
            'from' => $from,
            'to' => RiskReasonWords::level($to),
            'reasons' => NotificationMessage::join('; ', $reasons),
        ]);
    }
}
