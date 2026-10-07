<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_redirects_to_the_list_of_events()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('events.index'));
    }
}
