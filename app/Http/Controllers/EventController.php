<?php

namespace App\Http\Controllers;

use App\Actions\CreateEvent\CreateEvent;
use App\Actions\DeleteEvent\DeleteEvent;
use App\Actions\GetEvent\GetEvent;
use App\Actions\GetUpcomingEvents\GetUpcomingEvents;
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
     * Show the upcoming published events.
     */
    public function index(GetUpcomingEvents $getUpcomingEvents): Response
    {
        return Inertia::render('events/index', [
            'events' => $getUpcomingEvents->handle(),
        ]);
    }

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
            ...$getEvent->handle($event, $request->user()),
            'can' => [
                'update' => $request->user()?->can('update', $event) ?? false,
                'publish' => ($request->user()?->can('publish', $event) ?? false) && $event->canBePublished(),
                'cancel' => ($request->user()?->can('cancel', $event) ?? false) && $event->canBeCancelled(),
                'delete' => $request->user()?->can('delete', $event) ?? false,
                'viewAttendees' => $request->user()?->can('viewAttendees', $event) ?? false,
            ],
        ]);
    }

    /**
     * Show the form to edit an event.
     */
    public function edit(Event $event, GetEvent $getEvent): Response
    {
        return Inertia::render('events/edit', [
            'event' => $getEvent->handle($event)['event'],
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

    /**
     * Delete a draft event and show the events of the user.
     */
    public function destroy(Event $event, DeleteEvent $deleteEvent): RedirectResponse
    {
        $deleteEvent->handle($event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event deleted.')]);

        return to_route('organizer.events.index');
    }
}
