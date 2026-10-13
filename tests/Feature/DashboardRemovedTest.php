<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_url_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertNotFound();
    }

    public function test_login_goes_to_the_intended_page(): void
    {
        $user = User::factory()->create();

        $this->get(route('events.create'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('events.create'));
    }
}
