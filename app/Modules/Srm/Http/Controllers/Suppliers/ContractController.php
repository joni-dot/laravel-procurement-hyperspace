<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Srm\Actions\Contracts\DeleteContract;
use App\Modules\Srm\Actions\Contracts\SaveContract;
use App\Modules\Srm\Comments\CommentFeed;
use App\Modules\Srm\Enums\ContractStatus;
use App\Modules\Srm\Enums\ContractValuePeriod;
use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Http\Requests\Suppliers\ContractRequest;
use App\Modules\Srm\Models\Comment;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function show(Request $request, Supplier $supplier, Contract $contract, CommentFeed $commentFeed): Response
    {
        $contract->load([
            'type:id,name',
            // As the company knows them: someone who has left keeps the name they had then.
            'owner' => fn (BelongsTo $owner) => $owner->withNameKnownToCurrentCompany(),
            'reminders:id,contract_id,days_before',
            'documents' => fn ($query) => $query->orderBy('file_name'),
        ]);

        return Inertia::render('srm/suppliers/contracts/Show', [
            'supplier' => $supplier->only(['id', 'name']),
            'contract' => [
                ...$contract->only([
                    'id', 'title', 'contract_number', 'auto_renews', 'renewal_term_months',
                    'notice_period_days', 'value', 'currency_code', 'notes',
                ]),
                'type' => $contract->type->name,
                'owner' => $contract->owner?->name,
                'health' => $contract->health(),
                'starts_on' => $contract->starts_on?->toDateString(),
                'ends_on' => $contract->ends_on?->toDateString(),
                'action_by' => $contract->action_by?->toDateString(),
                'value_period' => $contract->value_period?->label(),
                'reminder_days' => $contract->reminders->pluck('days_before')->sortDesc()->values(),
                'documents' => $contract->documents->map(fn (Document $document) => $document->only(['id', 'file_name', 'file_size'])),
            ],
            'comments' => fn () => $commentFeed->forContract($contract, $supplier, $request->user(), CommentFeed::requestedLimit($request)),
            'can' => [
                'update' => $request->user()->can('update', $supplier),
                'comment' => $request->user()->can('create', [Comment::class, $supplier]),
            ],
        ]);
    }

    public function create(Supplier $supplier): Response
    {
        return Inertia::render('srm/suppliers/contracts/Create', [
            ...$this->formOptions(),
            'supplier' => $supplier->only(['id', 'name']),
        ]);
    }

    public function store(ContractRequest $request, Supplier $supplier, SaveContract $saveContract): RedirectResponse
    {
        $contract = $saveContract->handle($supplier->contracts()->make(), $request->contractAttributes(), $request->reminderDays());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contract added.')]);

        return to_route('suppliers.contracts.show', [$supplier, $contract]);
    }

    public function edit(Supplier $supplier, Contract $contract): Response
    {
        return Inertia::render('srm/suppliers/contracts/Edit', [
            ...$this->formOptions(),
            'supplier' => $supplier->only(['id', 'name']),
            'contract' => [
                ...$contract->only([
                    'id', 'title', 'contract_number', 'contract_type_id', 'status', 'owner_user_id', 'auto_renews',
                    'renewal_term_months', 'notice_period_days', 'value', 'currency_code', 'value_period', 'notes',
                ]),
                'starts_on' => $contract->starts_on?->toDateString(),
                'ends_on' => $contract->ends_on?->toDateString(),
                'reminder_days' => $contract->reminders()->orderByDesc('days_before')->pluck('days_before'),
            ],
        ]);
    }

    public function update(ContractRequest $request, Supplier $supplier, Contract $contract, SaveContract $saveContract): RedirectResponse
    {
        $saveContract->handle($contract, $request->contractAttributes(), $request->reminderDays());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contract updated.')]);

        return to_route('suppliers.contracts.show', [$supplier, $contract]);
    }

    public function destroy(Supplier $supplier, Contract $contract, DeleteContract $deleteContract): RedirectResponse
    {
        $deleteContract->handle($contract);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contract deleted.')]);

        return to_route('suppliers.show', $supplier);
    }

    /**
     * The choices shown in the contract form's drop-downs.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'statuses' => ContractStatus::options(),
            'contractTypes' => ContractType::query()->orderBy('name')->get(['id', 'name']),
            'owners' => User::query()->activeBuyersAndAdmins()->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::options(),
            'valuePeriods' => ContractValuePeriod::options(),
        ];
    }
}
