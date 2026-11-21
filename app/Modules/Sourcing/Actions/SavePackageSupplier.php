<?php

namespace App\Modules\Sourcing\Actions;

use App\Modules\Ppr\Models\Package;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a supplier to a package, or changes its status and note. Choosing a supplier moves the one chosen
 * before back to "Offer received", so a package keeps at most one.
 */
class SavePackageSupplier
{
    /**
     * The package's row stays locked until the save is done, so when two people change the package's suppliers
     * at once, the saves happen one after the other: the second choice wins instead of hitting the database's
     * one-chosen rule, and the same supplier can't be added twice.
     *
     * A saved one is read again inside the lock: it was loaded before, and someone may have changed its status
     * meanwhile (choosing another supplier moves it back to "Offer received"). Saved from the old copy, choosing it
     * again would look like no change and leave the package with no chosen supplier.
     *
     * @param  PackageSupplier  $candidate  a new one with its package and supplier set, or one already saved
     * @return QuoteStatus|null its status just before this save; null for a new one
     *
     * @throws ValidationException when someone else added the same supplier meanwhile
     * @throws ModelNotFoundException when someone else removed it meanwhile
     */
    public function handle(PackageSupplier $candidate, QuoteStatus $status, ?string $note): ?QuoteStatus
    {
        return DB::transaction(function () use ($candidate, $status, $note): ?QuoteStatus {
            Package::query()->whereKey($candidate->package_id)->lockForUpdate()->value('id');

            if (! $candidate->exists && $this->isAlreadyOnThePackage($candidate)) {
                throw ValidationException::withMessages(['supplier_id' => __('This supplier is already on the package.')]);
            }

            $previousStatus = $candidate->exists ? $candidate->refresh()->status : null;

            if ($status === QuoteStatus::Chosen) {
                $this->unchooseOthers($candidate);
            }

            $candidate->fill(['status' => $status, 'note' => $note])->save();

            return $previousStatus;
        });
    }

    private function isAlreadyOnThePackage(PackageSupplier $candidate): bool
    {
        return PackageSupplier::query()
            ->where('package_id', $candidate->package_id)
            ->where('supplier_id', $candidate->supplier_id)
            ->exists();
    }

    private function unchooseOthers(PackageSupplier $candidate): void
    {
        PackageSupplier::query()
            ->where('package_id', $candidate->package_id)
            ->where('status', QuoteStatus::Chosen)
            ->when($candidate->exists, fn ($others) => $others->whereKeyNot($candidate->id))
            ->update(['status' => QuoteStatus::OfferReceived]);
    }
}
