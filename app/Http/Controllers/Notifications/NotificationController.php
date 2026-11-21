<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Notifications\NotificationKinds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class NotificationController extends Controller
{
    private const int NOTIFICATIONS_PER_PAGE = 25;

    public function index(Request $request, NotificationKinds $kinds): Response
    {
        $notifications = Notification::query()
            ->visibleTo($request->user())
            ->latest()
            ->orderByDesc('id')
            ->paginate(self::NOTIFICATIONS_PER_PAGE);

        $descriptions = $kinds->describe($notifications->getCollection());

        return Inertia::render('notifications/Index', [
            'notifications' => $notifications->through(function (Notification $notification) use ($descriptions): array {
                $description = $descriptions[$notification->id]
                    ?? throw new LogicException("Notification [{$notification->id}] was not described by its kind.");

                return [
                    'id' => $notification->id,
                    'message' => $description->message,
                    'source' => $description->source,
                    'isRead' => $notification->read_at !== null,
                    'createdAt' => $notification->created_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Opening a notification marks it as read and goes to what it is about, such as a package or a contract.
     */
    public function show(Notification $notification): RedirectResponse
    {
        $notification->markAsRead();

        return redirect($notification->destination());
    }

    /**
     * Marks the notification as read.
     */
    public function update(Notification $notification): RedirectResponse
    {
        $notification->markAsRead();

        return back();
    }
}
