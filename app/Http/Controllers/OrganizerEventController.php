<?php

namespace App\Http\Controllers;

use App\Actions\GetOrganizerEvents\GetOrganizerEvents;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizerEventController extends Controller
{
    /**
     * Show the events of the logged-in user.
     */
    public function index(Request $request, GetOrganizerEvents $getOrganizerEvents): Response
    {
        return Inertia::render('organizer/events/index', $getOrganizerEvents->handle($request->user()));
    }
}
