<?php

namespace App\Modules\Srm\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Modules\Srm\Models\Certificate;
use App\Notifications\NotificationDescription;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Acme Oy: the ISO 9001 certificate expires on 1 Dec 2026." In the bell of the supplier's responsible buyer,
 * one per certificate, when its reminder day comes (app:send-certificate-reminders). Everyone in the company
 * may see suppliers and their certificates, so it stays visible; opening it goes to the supplier.
 */
final class CertificateExpiringNotifications implements NotificationKind
{
    public const string TYPE = 'certificate_expiring';

    /**
     * What is stored, as it is now, so the page needs no query per notification.
     *
     * @return array{supplier_id: int, supplier_name: string, certificate_id: int, certificate_type: string, expires_on: string}
     */
    public static function about(Certificate $certificate): array
    {
        return [
            'supplier_id' => $certificate->supplier_id,
            'supplier_name' => $certificate->supplier->name,
            'certificate_id' => $certificate->id,
            'certificate_type' => $certificate->type->name,
            'expires_on' => (string) $certificate->expires_on?->toDateString(),
        ];
    }

    public function types(): array
    {
        return [self::TYPE];
    }

    public function onlyVisibleTo(Builder $query, User $user): void
    {
        // Everyone in the company may see suppliers and their certificates.
    }

    public function describe(Collection $notifications): array
    {
        $descriptions = [];

        foreach ($notifications as $notification) {
            $descriptions[$notification->id] = new NotificationDescription(
                NotificationMessage::make(__(':supplier: the :certificate certificate expires on :date.'), [
                    'supplier' => (string) $notification->data['supplier_name'],
                    'certificate' => (string) $notification->data['certificate_type'],
                    'date' => NotificationMessage::date((string) $notification->data['expires_on']),
                ]),
                __('Suppliers'),
            );
        }

        return $descriptions;
    }

    public function destination(Notification $notification): string
    {
        return route('suppliers.show', $notification->data['supplier_id'], absolute: false);
    }
}
