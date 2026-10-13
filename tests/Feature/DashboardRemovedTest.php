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
}
