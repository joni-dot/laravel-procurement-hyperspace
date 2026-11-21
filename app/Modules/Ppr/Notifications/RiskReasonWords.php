<?php

namespace App\Modules\Ppr\Notifications;

use App\Modules\Ppr\Risk\RiskLevel;
use App\Modules\Ppr\Risk\RiskReason;
use App\Notifications\NotificationMessage;

/**
 * Risk levels and reasons in words, for the "risk level changed" notification, which is read on the
 * notifications page and in the summary email. The package pages put them into words in the browser
 * (resources/js/lib/ppr/packageRisk.ts); both use the same texts, so lang/en.json translates them once.
 */
final class RiskReasonWords
{
    public static function level(RiskLevel $level): string
    {
        return match ($level) {
            RiskLevel::Red => __('Red'),
            RiskLevel::Amber => __('Amber'),
            RiskLevel::None => __('No risk'),
        };
    }

    /**
     * @param  int|null  $floatDays  for the "late" reason
     * @param  string|null  $overdueMilestone  for the "overdue" reason
     * @param  string|null  $overdueDate  for the "overdue" reason, "YYYY-MM-DD"
     */
    public static function reason(RiskReason $reason, mixed $floatDays, mixed $overdueMilestone, mixed $overdueDate): NotificationMessage
    {
        return match ($reason) {
            RiskReason::ManualFlag => NotificationMessage::text(__('Flagged as high risk')),
            RiskReason::Overdue => self::overdue(is_string($overdueMilestone) ? $overdueMilestone : null, is_string($overdueDate) ? $overdueDate : null),
            RiskReason::Late => NotificationMessage::text(self::late(is_int($floatDays) ? $floatDays : null)),
            RiskReason::NoNeedDate => NotificationMessage::text(__('No need date planned, so its float is unknown')),
            RiskReason::NoOwner => NotificationMessage::text(__('No package owner')),
            RiskReason::OwnerDeactivated => NotificationMessage::text(__('The package owner is deactivated')),
            RiskReason::NoBuyer => NotificationMessage::text(__('No responsible buyer')),
            RiskReason::BuyerDeactivated => NotificationMessage::text(__('The responsible buyer is deactivated')),
            RiskReason::SupplierAtRisk => NotificationMessage::text(__('The chosen supplier is high risk or archived')),
        };
    }

    private static function overdue(?string $milestone, ?string $date): NotificationMessage
    {
        if ($milestone === null || $milestone === '') {
            return NotificationMessage::text(__('Overdue'));
        }

        return $date === null
            ? NotificationMessage::make(__('Overdue: :milestone'), ['milestone' => $milestone])
            : NotificationMessage::make(__('Overdue: :milestone was due on :date'), ['milestone' => $milestone, 'date' => NotificationMessage::date($date)]);
    }

    private static function late(?int $floatDays): string
    {
        if ($floatDays === null || $floatDays >= 0) {
            return __('Short on time: :float', ['float' => $floatDays === null ? '–' : self::daysToSpare($floatDays)]);
        }

        return $floatDays === -1
            ? __('Late: expected 1 day after the need date')
            : __('Late: expected :count days after the need date', ['count' => -$floatDays]);
    }

    private static function daysToSpare(int $days): string
    {
        return match ($days) {
            0 => __('No days to spare'),
            1 => __('1 day to spare'),
            default => __(':count days to spare', ['count' => $days]),
        };
    }
}
