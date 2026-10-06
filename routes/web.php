<?php

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('events/{event}', [EventController::class, 'show'])
    ->name('events.show')
    ->can('view', 'event');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
