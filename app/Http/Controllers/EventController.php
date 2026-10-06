<?php

namespace App\Http\Controllers;

use App\Actions\GetEvent\GetEvent;
use App\Models\Event;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
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
