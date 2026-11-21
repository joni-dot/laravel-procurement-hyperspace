<?php

namespace App\Modules\Ppr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ppr\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MarkAllNotificationsReadController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Notification::query()
            ->visibleTo($request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All notifications marked as read.')]);

        return back();
    }
}
