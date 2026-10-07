<?php

namespace App\Http\Controllers;

use App\Actions\PublishEvent\PublishEvent;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class EventPublicationController extends Controller
{
    /**
     * Publish the event and show its page.
     */
    public function store(Event $event, PublishEvent $publishEvent): RedirectResponse
    {
        $publishEvent->handle($event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event published.')]);

        return to_route('events.show', $event);
    }
}
