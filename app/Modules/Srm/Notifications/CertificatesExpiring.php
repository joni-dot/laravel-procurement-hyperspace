<?php

namespace App\Modules\Srm\Notifications;

use App\Modules\Srm\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * One email per recipient and company listing every certificate that needs attention.
 *
 * The links are made when the email is queued, inside the company, so they lead into that company.
 */
class CertificatesExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var list<array{supplier: string, type: string, expires_on: string, days_left: int, url: string}>
     */
    private readonly array $items;

    private readonly string $listUrl;

    /**
     * @param  Collection<int, Certificate>  $certificates
     */
    public function __construct(private readonly string $companyName, Collection $certificates)
    {
        $this->afterCommit();

        $this->items = array_values($certificates
            ->sortBy('expires_on')
            ->map(fn (Certificate $certificate) => [
                'supplier' => $certificate->supplier->name,
                'type' => $certificate->type->name,
                'expires_on' => (string) $certificate->expires_on?->toDateString(),
                'days_left' => (int) today()->diffInDays($certificate->expires_on),
                'url' => route('suppliers.show', $certificate->supplier_id),
            ])
            ->all());

        $this->listUrl = route('suppliers.index');
    }

    /**
     * @return list<string>
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        $count = count($this->items);

        return (new MailMessage)
            ->subject(trans_choice(':company: :count certificate is expiring soon|:company: :count certificates are expiring soon', $count, ['company' => $this->companyName]))
            ->markdown('mail.srm.certificates-expiring', [
                'intro' => trans_choice('This certificate needs to be renewed soon:|These certificates need to be renewed soon:', $count),
                'items' => $this->items,
                'url' => $this->listUrl,
            ]);
    }

    /**
     * @return list<array{supplier: string, type: string, expires_on: string, days_left: int, url: string}>
     */
    public function items(): array
    {
        return $this->items;
    }
}
