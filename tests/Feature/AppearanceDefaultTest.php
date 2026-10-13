<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_dark_when_there_is_no_appearance_cookie(): void
    {
        $this->get(route('events.index'))
            ->assertOk()
            ->assertSee('class="dark"', false);
    }

    public function test_the_page_is_not_dark_when_the_appearance_cookie_is_light(): void
    {
        $this->withUnencryptedCookie('appearance', 'light')
            ->get(route('events.index'))
            ->assertOk()
            ->assertDontSee('class="dark"', false);
    }

    public function test_the_page_keeps_the_system_choice(): void
    {
        $this->withUnencryptedCookie('appearance', 'system')
            ->get(route('events.index'))
            ->assertOk()
            ->assertDontSee('class="dark"', false)
            ->assertSee("const appearance = 'system'", false);
    }
}
