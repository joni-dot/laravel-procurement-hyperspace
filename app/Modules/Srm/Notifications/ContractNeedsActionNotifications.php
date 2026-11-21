<?php

namespace App\Modules\Srm\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Modules\Srm\Models\Contract;
use App\Notifications\NotificationDescription;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Acme Oy: the last day to cancel Cleaning services is 1 Dec 2026." In the bell of the contract's owner (or
 * the supplier's responsible buyer), one per contract, when its reminder day comes (app:send-contract-reminders).
 * Contract details are only for buyers and admins, so someone who has lost that right no longer sees it.
 * Prices are never stored. Opening it goes to the contract.
 */
final class ContractNeedsActionNotifications implements NotificationKind
{
    public const string TYPE = 'contract_needs_action';

    /**
     * What is stored, as it is now, so the page needs no query per notification. No prices.
     *
     * @return array{supplier_id: int, supplier_name: string, contract_id: int, contract_title: string, auto_renews: bool, action_by: string}
     */
    public static function about(Contract $contract): array
    {
        return [
            'supplier_id' => $contract->supplier_id,
            'supplier_name' => $contract->supplier->name,
            'contract_id' => $contract->id,
            'contract_title' => $contract->title,
            'auto_renews' => $contract->auto_renews,
            'action_by' => (string) $contract->action_by?->toDateString(),
        ];
    }

    public function types(): array
    {
        return [self::TYPE];
    }

    /**
     * The person's role in this company as it is now, so the rule needs no join.
     */
    public function onlyVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('viewDetails', Contract::class)) {
            $query->whereRaw('false');
        }
    }

    public function describe(Collection $notifications): array
    {
        $descriptions = [];

        foreach ($notifications as $notification) {
            $replacements = [
                'supplier' => (string) $notification->data['supplier_name'],
                'contract' => (string) $notification->data['contract_title'],
                'date' => NotificationMessage::date((string) $notification->data['action_by']),
            ];

            $descriptions[$notification->id] = new NotificationDescription(
                $notification->data['auto_renews']
                    ? NotificationMessage::make(__(':supplier: the last day to cancel :contract is :date.'), $replacements)
                    : NotificationMessage::make(__(':supplier: :contract ends on :date.'), $replacements),
                __('Suppliers'),
            );
        }

        return $descriptions;
    }

    public function destination(Notification $notification): string
    {
        return route('suppliers.contracts.show', [$notification->data['supplier_id'], $notification->data['contract_id']], absolute: false);
    }
}
