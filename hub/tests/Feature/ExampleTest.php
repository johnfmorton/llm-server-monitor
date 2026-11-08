<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_sends_guests_to_the_login_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
