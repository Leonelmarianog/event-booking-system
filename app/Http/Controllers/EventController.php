<?php

namespace App\Http\Controllers;

use App\Actions\CreateEvent\CreateEvent;
use App\Actions\GetEvent\GetEvent;
use App\Http\Requests\StoreEventRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    /**
     * Show the form to create an event.
     */
    public function create(): Response
    {
        return Inertia::render('events/create');
    }

    /**
     * Create a draft event and show its page.
     */
    public function store(StoreEventRequest $request, CreateEvent $createEvent): RedirectResponse
    {
        $event = $createEvent->handle($request->user(), $request->eventAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event created.')]);

        return to_route('events.show', $event);
    }

    /**
     * Show the page of one event.
     */
    public function show(Event $event, GetEvent $getEvent): Response
    {
        return Inertia::render('events/show', [
            'event' => $getEvent->handle($event),
        ]);
    }
}
