<?php

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('events/{event}', [EventController::class, 'show'])
    ->whereNumber('event')
    ->name('events.show')
    ->can('view', 'event');

Route::middleware('auth')->group(function () {
    Route::get('events/create', [EventController::class, 'create'])->name('events.create');
    Route::post('events', [EventController::class, 'store'])
        ->middleware('throttle:event-writes')
        ->name('events.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
