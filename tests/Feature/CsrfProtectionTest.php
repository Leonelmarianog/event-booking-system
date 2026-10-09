<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Crypt;

beforeEach(function () {
    // Laravel skips the CSRF check while tests run. This subclass turns the skip off,
    // so the real check runs.
    $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $this->user = User::factory()->create();
    $this->event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 10]);
    $this->token = 'csrf-token-of-the-session';
});

test('a POST from another site without the token is rejected', function () {
    $this->actingAs($this->user)
        ->withSession(['_token' => $this->token])
        ->withHeader('Sec-Fetch-Site', 'cross-site')
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertStatus(419);

    expect(Booking::count())->toBe(0);
});

test('an Inertia request with the X-XSRF-TOKEN header passes', function () {
    $prefix = CookieValuePrefix::create('XSRF-TOKEN', app('encrypter')->getKey());

    $this->actingAs($this->user)
        ->withSession(['_token' => $this->token])
        ->withHeader('X-XSRF-TOKEN', Crypt::encrypt($prefix.$this->token, false))
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event));

    expect(Booking::count())->toBe(1);
});
