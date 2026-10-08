<?php

namespace App\Http\Controllers;

use App\Actions\GetEventAttendees\GetEventAttendees;
use App\Models\Event;
use Inertia\Inertia;
use Inertia\Response;

class EventAttendeeController extends Controller
{
    /**
     * Show the attendee list of the event.
     */
    public function index(Event $event, GetEventAttendees $getEventAttendees): Response
    {
        return Inertia::render('organizer/events/attendees', $getEventAttendees->handle($event));
    }
}
