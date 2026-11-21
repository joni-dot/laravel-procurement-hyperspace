<?php

namespace App\Modules\Srm\Notifications;

use App\Modules\Srm\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * One email per recipient and company listing every contract that needs a decision. Prices are left out on purpose.
 *
 * The links are made when the email is queued, inside the company, so they lead into that company.
 */
class ContractsNeedAction extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var list<array{supplier: string, title: string, action: string, action_by: string, days_left: int, url: string}>
     */
    private readonly array $items;

    private readonly string $listUrl;

    /**
     * @param  Collection<int, Contract>  $contracts
     */
    public function __construct(private readonly string $companyName, Collection $contracts)
    {
        $this->afterCommit();

        $this->items = array_values($contracts
            ->sortBy('action_by')
            ->map(fn (Contract $contract) => [
                'supplier' => $contract->supplier->name,
                'title' => $contract->title,
                'action' => $contract->auto_renews ? __('Last day to cancel') : __('Ends'),
                'action_by' => (string) $contract->action_by?->toDateString(),
                'days_left' => (int) today()->diffInDays($contract->action_by),
                'url' => route('suppliers.contracts.show', [$contract->supplier_id, $contract->id]),
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
            ->subject(trans_choice(':company: :count contract needs your attention|:company: :count contracts need your attention', $count, ['company' => $this->companyName]))
            ->markdown('mail.srm.contracts-need-action', [
                'intro' => trans_choice('This contract needs a decision soon:|These contracts need a decision soon:', $count),
                'items' => $this->items,
                'url' => $this->listUrl,
            ]);
    }

    /**
     * @return list<array{supplier: string, title: string, action: string, action_by: string, days_left: int, url: string}>
     */
    public function items(): array
    {
        return $this->items;
    }
}
