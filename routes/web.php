<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventPublicationController;
use App\Http\Controllers\OrganizerEventController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/events')->name('home');

Route::get('events', [EventController::class, 'index'])->name('events.index');

Route::get('events/{event}', [EventController::class, 'show'])
    ->whereNumber('event')
    ->name('events.show')
    ->can('view', 'event');

Route::middleware('auth')->group(function () {
    Route::get('events/create', [EventController::class, 'create'])->name('events.create');
    Route::post('events', [EventController::class, 'store'])
        ->middleware('throttle:event-writes')
        ->name('events.store');
    Route::get('events/{event}/edit', [EventController::class, 'edit'])
        ->whereNumber('event')
        ->name('events.edit')
        ->can('update', 'event');
    Route::put('events/{event}', [EventController::class, 'update'])
        ->whereNumber('event')
        ->middleware('throttle:event-writes')
        ->name('events.update')
        ->can('update', 'event');
    Route::delete('events/{event}', [EventController::class, 'destroy'])
        ->whereNumber('event')
        ->middleware('throttle:event-writes')
        ->name('events.destroy')
        ->can('delete', 'event');
    Route::post('events/{event}/publication', [EventPublicationController::class, 'store'])
        ->whereNumber('event')
        ->middleware('throttle:event-writes')
        ->name('events.publication.store')
        ->can('publish', 'event');
    Route::post('events/{event}/bookings', [BookingController::class, 'store'])
        ->whereNumber('event')
        ->middleware('throttle:bookings')
        ->name('events.bookings.store')
        ->can('view', 'event');
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('organizer/events', [OrganizerEventController::class, 'index'])
        ->name('organizer.events.index');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
