<?php

namespace App\Http\Controllers;

use App\Actions\CreateEvent\CreateEvent;
use App\Actions\GetEvent\GetEvent;
use App\Actions\UpdateEvent\UpdateEvent;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function show(Request $request, Event $event, GetEvent $getEvent): Response
    {
        return Inertia::render('events/show', [
            'event' => $getEvent->handle($event),
            'can' => [
                'update' => $request->user()?->can('update', $event) ?? false,
            ],
        ]);
    }

    /**
     * Show the form to edit an event.
     */
    public function edit(Event $event, GetEvent $getEvent): Response
    {
        return Inertia::render('events/edit', [
            'event' => $getEvent->handle($event),
        ]);
    }

    /**
     * Update the event and show its page.
     */
    public function update(UpdateEventRequest $request, Event $event, UpdateEvent $updateEvent): RedirectResponse
    {
        $updateEvent->handle($event, $request->eventAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event updated.')]);

        return to_route('events.show', $event);
    }
}
